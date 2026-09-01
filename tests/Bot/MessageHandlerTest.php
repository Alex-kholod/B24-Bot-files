<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Bot;

use B24DocsBot\Bitrix\B24ApiException;
use B24DocsBot\Bot\BotEvent;
use B24DocsBot\Bot\MessageHandler;
use B24DocsBot\Service\ChecklistWriter;
use B24DocsBot\Service\ClientResolver;
use B24DocsBot\Service\FileAttacher;
use B24DocsBot\Service\TaskResolver;
use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\PendingFileRepository;
use B24DocsBot\Storage\ProcessedMessageRepository;
use B24DocsBot\Storage\SettingsRepository;
use B24DocsBot\Storage\TaskLinkRepository;
use B24DocsBot\Tests\Bitrix\FakeB24Api;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MessageHandlerTest extends TestCase
{
    private FakeB24Api $api;
    private ProcessedMessageRepository $processed;
    private PendingFileRepository $pending;
    private MessageHandler $handler;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $db->migrate();

        $this->api = new FakeB24Api();
        $this->api->dialogs[5] = ['crm_entity_type' => 'CONTACT', 'crm_entity_id' => '123'];
        $this->api->crmEntities['CONTACT:123'] = ['ID' => 123, 'TITLE' => 'Иванов Иван', 'ASSIGNED_BY_ID' => 42];

        $links = new TaskLinkRepository($db->pdo());
        $settings = new SettingsRepository($db->pdo());

        $this->processed = new ProcessedMessageRepository($db->pdo());
        $this->pending = new PendingFileRepository($db->pdo());

        $this->handler = new MessageHandler(
            $this->processed,
            $this->pending,
            new ClientResolver($this->api),
            new TaskResolver($this->api, $links, 1, 0, 3),
            new FileAttacher($this->api, new ChecklistWriter($this->api, $settings, $links, 'Документы от клиента')),
            new NullLogger(),
            10
        );

        $this->now = new DateTimeImmutable('2026-08-31 12:30:00');
    }

    private function event(array $overrides = []): BotEvent
    {
        $defaults = [
            'event' => 'ONIMBOTV2MESSAGEADD',
            'botId' => 456,
            'messageId' => 789,
            'chatId' => 5,
            'authorId' => 1269,
            'chatEntityType' => 'LINES',
            'authorIsBot' => false,
            'fileIds' => [77],
        ];

        $values = array_replace($defaults, $overrides);

        return new BotEvent(...$values);
    }

    public function testHappyPathAttachesFileAndMarksMessageProcessed(): void
    {
        $this->handler->handle($this->event(), $this->now);

        self::assertSame([[5, 77]], $this->api->savedFiles);
        self::assertTrue($this->processed->isProcessed(789));
        self::assertCount(0, $this->pending->due($this->now));
    }

    public function testIgnoresMessagesFromBots(): void
    {
        $this->handler->handle($this->event(['authorIsBot' => true]), $this->now);

        self::assertSame([], $this->api->savedFiles);
        self::assertFalse($this->processed->isProcessed(789));
    }

    public function testIgnoresNonOpenLineChats(): void
    {
        $this->handler->handle($this->event(['chatEntityType' => '']), $this->now);

        self::assertSame([], $this->api->savedFiles);
    }

    public function testIgnoresMessagesWithoutFiles(): void
    {
        $this->handler->handle($this->event(['fileIds' => []]), $this->now);

        self::assertSame([], $this->api->savedFiles);
        self::assertFalse($this->processed->isProcessed(789));
    }

    public function testRepeatedDeliveryDoesNothing(): void
    {
        $this->handler->handle($this->event(), $this->now);
        $this->handler->handle($this->event(), $this->now);

        self::assertCount(1, $this->api->savedFiles);
        self::assertCount(1, $this->api->addedTasks);
    }

    public function testFilesAreQueuedBeforeAnyApiCall(): void
    {
        $this->api->throwOnDialog = new B24ApiException('портал недоступен', 'INTERNAL_SERVER_ERROR');

        $this->handler->handle($this->event(), $this->now);

        self::assertCount(1, $this->pending->due($this->now->modify('+1 minute')));
        self::assertFalse($this->processed->isProcessed(789), 'сообщение не считается обработанным');
    }

    public function testChatWithoutCrmEntityLeavesFileInQueue(): void
    {
        $this->api->dialogs[5] = ['crm' => 'N'];

        $this->handler->handle($this->event(), $this->now);

        self::assertSame([], $this->api->savedFiles);
        self::assertCount(1, $this->pending->due($this->now->modify('+1 minute')));
        self::assertFalse($this->processed->isProcessed(789));
    }

    public function testCronCanFinishWorkAfterCrmBindingAppears(): void
    {
        $this->api->dialogs[5] = ['crm' => 'N'];
        $this->handler->handle($this->event(), $this->now);

        $this->api->dialogs[5] = ['crm_entity_type' => 'CONTACT', 'crm_entity_id' => '123'];
        $later = $this->now->modify('+5 minutes');

        foreach ($this->pending->due($later) as $row) {
            self::assertTrue($this->handler->processRow($row, $later));
        }

        self::assertSame([[5, 77]], $this->api->savedFiles);
        self::assertCount(0, $this->pending->due($later));
    }

    public function testMultipleFilesInOneMessage(): void
    {
        $this->handler->handle($this->event(['fileIds' => [77, 78]]), $this->now);

        self::assertSame([[5, 77], [5, 78]], $this->api->savedFiles);
        self::assertCount(1, $this->api->addedTasks, 'задача создаётся один раз на сообщение');
    }

    public function testFailureOfOneFileDoesNotBlockAnother(): void
    {
        $api = new class extends FakeB24Api {
            public function saveChatFileToDisk(int $chatId, int $chatFileId): int
            {
                if ($chatFileId === 77) {
                    throw new B24ApiException('лимит', 'QUERY_LIMIT_EXCEEDED');
                }

                return parent::saveChatFileToDisk($chatId, $chatFileId);
            }
        };
        $api->dialogs[5] = ['crm_entity_type' => 'CONTACT', 'crm_entity_id' => '123'];

        $db = new Database(':memory:');
        $db->migrate();
        $links = new TaskLinkRepository($db->pdo());
        $pending = new PendingFileRepository($db->pdo());
        $processed = new ProcessedMessageRepository($db->pdo());

        $handler = new MessageHandler(
            $processed,
            $pending,
            new ClientResolver($api),
            new TaskResolver($api, $links, 1, 0, 3),
            new FileAttacher($api, new ChecklistWriter($api, new SettingsRepository($db->pdo()), $links, 'Документы')),
            new NullLogger(),
            10
        );

        $handler->handle($this->event(['fileIds' => [77, 78]]), $this->now);

        self::assertSame([[5, 78]], $api->savedFiles);
        self::assertCount(1, $pending->due($this->now->modify('+1 minute')));
        self::assertFalse($processed->isProcessed(789), 'останется незакрытым, пока есть незавершённые файлы');
    }

    public function testProcessRowDoesNotTouchBitrixWhenRowAlreadyClaimedByAnotherWorker(): void
    {
        // Симулируем гонку: вебхук и cron-тик читают одну и ту же строку. Второй воркер
        // (в данном тесте — захват аренды напрямую) успевает первым.
        $this->handler->handle($this->event(), $this->now);

        // handle() уже обработал строку до конца (markDone), поэтому вручную создадим ситуацию
        // "строка есть, но аренда уже занята" на новой независимой строке.
        $rowId = $this->pending->enqueue(999, 5, 88, 'doc.pdf', $this->now);
        $row = $this->pending->newForMessage(999)[0];

        self::assertTrue($this->pending->claim($rowId, $this->now, 5), 'первый воркер захватывает строку');

        $savedBefore = count($this->api->savedFiles);
        $result = $this->handler->processRow($row, $this->now);

        self::assertFalse($result, 'processRow не должен считать строку обработанной, если аренду держит другой воркер');
        self::assertSame($savedBefore, count($this->api->savedFiles), 'Битрикс24 не должен вызываться повторно');
    }
}
