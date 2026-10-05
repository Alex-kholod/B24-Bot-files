<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Bot;

use B24DocsBot\Bitrix\B24ApiException;
use B24DocsBot\Bot\BotEvent;
use B24DocsBot\Bot\MessageHandler;
use B24DocsBot\Service\DealDocumentPublisher;
use B24DocsBot\Service\DealResolver;
use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\DealCommentRepository;
use B24DocsBot\Storage\PendingFileRepository;
use B24DocsBot\Storage\ProcessedMessageRepository;
use B24DocsBot\Tests\Bitrix\FakeB24Api;
use B24DocsBot\Tests\Bitrix\FakeFileDownloader;
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
        $this->api->dialogs[5] = ['entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|123|DEAL|5547'];

        $this->processed = new ProcessedMessageRepository($db->pdo());
        $this->pending = new PendingFileRepository($db->pdo());
        $this->handler = $this->handlerFor($this->api, $db, $this->processed, $this->pending);

        $this->now = new DateTimeImmutable('2026-08-31 12:30:00');
    }

    private function handlerFor(
        FakeB24Api $api,
        Database $db,
        ProcessedMessageRepository $processed,
        PendingFileRepository $pending
    ): MessageHandler {
        return new MessageHandler(
            $processed,
            $pending,
            new DealResolver($api),
            new DealDocumentPublisher(
                $api,
                new FakeFileDownloader(),
                new DealCommentRepository($db->pdo()),
                new NullLogger(),
                sys_get_temp_dir() . '/b24-docs-bot-test-locks'
            ),
            new NullLogger(),
            10
        );
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

    public function testHappyPathPublishesCommentToDealAndMarksMessageProcessed(): void
    {
        $this->handler->handle($this->event(), $this->now);

        self::assertSame([77], $this->api->fetchedFiles);
        self::assertCount(1, $this->api->comments);
        self::assertSame(5547, array_values($this->api->comments)[0][0]);
        self::assertCount(1, $this->api->pinned);
        self::assertTrue($this->processed->isProcessed(789));
        self::assertCount(0, $this->pending->due($this->now));
    }

    public function testIgnoresMessagesFromBots(): void
    {
        $this->handler->handle($this->event(['authorIsBot' => true]), $this->now);

        self::assertSame([], $this->api->fetchedFiles);
        self::assertFalse($this->processed->isProcessed(789));
    }

    public function testIgnoresNonOpenLineChats(): void
    {
        $this->handler->handle($this->event(['chatEntityType' => '']), $this->now);

        self::assertSame([], $this->api->fetchedFiles);
    }

    public function testIgnoresMessagesWithoutFiles(): void
    {
        $this->handler->handle($this->event(['fileIds' => []]), $this->now);

        self::assertSame([], $this->api->fetchedFiles);
        self::assertFalse($this->processed->isProcessed(789));
    }

    public function testRepeatedDeliveryDoesNothing(): void
    {
        $this->handler->handle($this->event(), $this->now);
        $this->handler->handle($this->event(), $this->now);

        self::assertCount(1, $this->api->fetchedFiles);
        self::assertCount(1, $this->api->comments);
    }

    public function testFilesAreQueuedBeforeAnyApiCall(): void
    {
        $this->api->throwOnDialog = new B24ApiException('портал недоступен', 'INTERNAL_SERVER_ERROR');

        $this->handler->handle($this->event(), $this->now);

        self::assertCount(1, $this->pending->due($this->now->modify('+1 minute')));
        self::assertFalse($this->processed->isProcessed(789), 'сообщение не считается обработанным');
    }

    public function testChatWithoutDealLeavesFileInQueue(): void
    {
        $this->api->dialogs[5] = ['entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|123|DEAL|0'];

        $this->handler->handle($this->event(), $this->now);

        self::assertSame([], $this->api->fetchedFiles);
        self::assertCount(1, $this->pending->due($this->now->modify('+1 minute')));
        self::assertFalse($this->processed->isProcessed(789));
    }

    public function testCronCanFinishWorkAfterDealIsBound(): void
    {
        $this->api->dialogs[5] = ['crm' => 'N'];
        $this->handler->handle($this->event(), $this->now);

        $this->api->dialogs[5] = ['entity_data_2' => 'DEAL|5547'];
        $later = $this->now->modify('+5 minutes');

        foreach ($this->pending->due($later) as $row) {
            self::assertTrue($this->handler->processRow($row, $later));
        }

        self::assertSame([77], $this->api->fetchedFiles);
        self::assertCount(0, $this->pending->due($later));
    }

    public function testMultipleFilesInOneMessageGoToOneComment(): void
    {
        $this->handler->handle($this->event(['fileIds' => [77, 78]]), $this->now);

        self::assertSame([77, 78], $this->api->fetchedFiles);
        self::assertCount(1, $this->api->comments);
        self::assertCount(2, array_values($this->api->comments)[0][2]);
    }

    public function testFailureOfOneFileDoesNotBlockAnother(): void
    {
        $api = new class extends FakeB24Api {
            public function getChatFileDownloadUrl(int $fileId): string
            {
                if ($fileId === 77) {
                    throw new B24ApiException('лимит', 'QUERY_LIMIT_EXCEEDED');
                }

                return parent::getChatFileDownloadUrl($fileId);
            }
        };
        $api->dialogs[5] = ['entity_data_2' => 'DEAL|5547'];

        $db = new Database(':memory:');
        $db->migrate();
        $pending = new PendingFileRepository($db->pdo());
        $processed = new ProcessedMessageRepository($db->pdo());
        $handler = $this->handlerFor($api, $db, $processed, $pending);

        $handler->handle($this->event(['fileIds' => [77, 78]]), $this->now);

        self::assertSame([78], $api->fetchedFiles);
        self::assertCount(1, $api->comments);
        self::assertCount(1, $pending->due($this->now->modify('+1 minute')));
        self::assertFalse($processed->isProcessed(789), 'останется незакрытым, пока есть незавершённые файлы');
    }

    public function testProcessRowDoesNotTouchBitrixWhenRowAlreadyClaimedByAnotherWorker(): void
    {
        $rowId = $this->pending->enqueue(999, 5, 88, 'doc.pdf', $this->now);
        $row = $this->pending->newForMessage(999)[0];

        self::assertTrue($this->pending->claim($rowId, $this->now, 5), 'первый воркер захватывает строку');

        $result = $this->handler->processRow($row, $this->now);

        self::assertFalse($result, 'processRow не должен считать строку обработанной, если аренду держит другой воркер');
        self::assertSame([], $this->api->fetchedFiles, 'Битрикс24 не должен вызываться повторно');
    }
}
