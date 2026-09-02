<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Service;

use B24DocsBot\Bitrix\B24ApiException;
use B24DocsBot\Service\ChecklistWriter;
use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\SettingsRepository;
use B24DocsBot\Storage\TaskLinkRepository;
use B24DocsBot\Tests\Bitrix\FakeB24Api;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ChecklistWriterTest extends TestCase
{
    private FakeB24Api $api;
    private SettingsRepository $settings;
    private TaskLinkRepository $links;
    private ChecklistWriter $writer;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $db->migrate();

        $this->api = new FakeB24Api();
        $this->settings = new SettingsRepository($db->pdo());
        $this->links = new TaskLinkRepository($db->pdo());
        $this->links->save('crm:CONTACT:123', 'CONTACT', 123, 555);
        $this->writer = new ChecklistWriter($this->api, $this->settings, $this->links, 'Документы от клиента');
        $this->now = new DateTimeImmutable('2026-08-31 12:30:00');
    }

    private function write(int $pendingId = 1): int
    {
        return $this->writer->write('crm:CONTACT:123', 555, 9077, 'akt.pdf', 'https://disk/9077', $this->now, $pendingId);
    }

    public function testCreatesChecklistRootOnFirstWrite(): void
    {
        $this->write();

        $root = $this->api->addedChecklistItems[0][1];

        self::assertSame('Документы от клиента', $root['TITLE']);
        self::assertSame(0, $root['PARENT_ID']);
        self::assertGreaterThan(0, $this->links->find('crm:CONTACT:123')['checklist_id']);
    }

    public function testReusesCachedChecklistRoot(): void
    {
        $this->write();
        $rootId = $this->links->find('crm:CONTACT:123')['checklist_id'];
        $countAfterFirst = count($this->api->addedChecklistItems);

        $this->write();

        self::assertSame($rootId, $this->links->find('crm:CONTACT:123')['checklist_id']);
        self::assertCount($countAfterFirst + 1, $this->api->addedChecklistItems, 'корень не пересоздаётся');
    }

    public function testDetectsAttachmentsSupportAndStoresFlag(): void
    {
        $this->api->checklistAcceptsAttachments = true;

        $this->write();

        self::assertSame('1', $this->settings->get(ChecklistWriter::SETTING_KEY));
        self::assertTrue($this->writer->attachmentsSupported());
    }

    public function testAttachmentModeAddsItemWithFileAndWithoutTaskAttachment(): void
    {
        $this->api->checklistAcceptsAttachments = true;

        $this->write();
        $item = end($this->api->addedChecklistItems)[1];

        self::assertSame('akt.pdf — 31.08.2026 12:30', $item['TITLE']);
        self::assertSame([9077], $item['ATTACHMENTS']);
        self::assertSame([], $this->api->attachedFiles);
    }

    public function testDetectsMissingAttachmentsSupportAndStoresFlag(): void
    {
        $this->api->checklistAcceptsAttachments = false;

        $this->write();

        self::assertSame('0', $this->settings->get(ChecklistWriter::SETTING_KEY));
        self::assertFalse($this->writer->attachmentsSupported());
    }

    public function testFallbackModeAttachesFileToTaskAndWritesLink(): void
    {
        $this->api->checklistAcceptsAttachments = false;

        $itemId = $this->write();

        self::assertSame([9077], $this->api->attachedFiles[555]);
        self::assertSame(
            'akt.pdf — 31.08.2026 12:30 — https://disk/9077',
            $this->api->getChecklistItem(555, $itemId)['TITLE'],
            'после неудачной пробы заголовок пункта дополняется ссылкой'
        );
    }

    public function testFallbackModeWritesLinkDirectlyWhenModeAlreadyKnown(): void
    {
        $this->settings->set(ChecklistWriter::SETTING_KEY, '0');

        $this->write();
        $item = end($this->api->addedChecklistItems)[1];

        self::assertSame('akt.pdf — 31.08.2026 12:30 — https://disk/9077', $item['TITLE']);
        self::assertArrayNotHasKey('ATTACHMENTS', $item);
    }

    public function testStoredFlagIsReusedWithoutProbing(): void
    {
        $this->settings->set(ChecklistWriter::SETTING_KEY, '0');
        $this->api->checklistAcceptsAttachments = true;

        $this->write();
        $item = end($this->api->addedChecklistItems)[1];

        self::assertArrayNotHasKey('ATTACHMENTS', $item, 'режим взят из настроек, проба не выполняется');
        self::assertSame([9077], $this->api->attachedFiles[555]);
    }

    public function testProbeIsPerformedOnlyOnce(): void
    {
        $this->api->checklistAcceptsAttachments = false;

        $this->write();
        $this->api->attachedFiles = [];
        $this->write();

        self::assertSame('0', $this->settings->get(ChecklistWriter::SETTING_KEY));
        self::assertSame([9077], $this->api->attachedFiles[555]);
    }

    public function testInterruptedFirstWriteFollowedByRetryResultsInExactlyOneCorrectlyTitledItem(): void
    {
        // Проба ещё не пройдена (флаг не известен), режим окажется "без вложений". Первый
        // проход должен упасть ровно после того, как пункт чек-листа уже создан, но до того,
        // как флаг поддержки и финальный заголовок зафиксированы — это и есть окно, в которое
        // раньше терялась атомарность и появлялся дубль.
        $api = new class extends FakeB24Api {
            public bool $failNextAttach = true;

            public function attachFilesToTask(int $taskId, array $diskFileIds): void
            {
                if ($this->failNextAttach) {
                    $this->failNextAttach = false;

                    throw new \B24DocsBot\Bitrix\B24ApiException('лимит', 'QUERY_LIMIT_EXCEEDED');
                }

                parent::attachFilesToTask($taskId, $diskFileIds);
            }
        };
        $api->checklistAcceptsAttachments = false;

        $db = new Database(':memory:');
        $db->migrate();
        $settings = new SettingsRepository($db->pdo());
        $links = new TaskLinkRepository($db->pdo());
        $links->save('crm:CONTACT:123', 'CONTACT', 123, 555);
        $writer = new ChecklistWriter($api, $settings, $links, 'Документы от клиента');

        $pendingId = 42;

        try {
            $writer->write('crm:CONTACT:123', 555, 9077, 'akt.pdf', 'https://disk/9077', $this->now, $pendingId);
            self::fail('первая попытка должна была прерваться исключением');
        } catch (\B24DocsBot\Bitrix\B24ApiException $exception) {
            // ожидаемо — ровно то, что делает retry-очередь: помечает строку failed/new и повторяет позже.
        }

        // Создано ровно два пункта: корень чек-листа и сам пункт файла (голый заголовок, без
        // ссылки) — второго пункта файла ещё нет.
        self::assertCount(2, $api->addedChecklistItems);
        $itemIdAfterFirstAttempt = array_key_last($api->checklistItems[555]);

        // Повтор — как это сделал бы cron с той же строкой очереди (тот же pendingId).
        $finalItemId = $writer->write('crm:CONTACT:123', 555, 9077, 'akt.pdf', 'https://disk/9077', $this->now, $pendingId);

        self::assertCount(
            2,
            $api->addedChecklistItems,
            'повтор не должен создавать ни второй корень, ни второй пункт чек-листа'
        );
        self::assertSame(
            $itemIdAfterFirstAttempt,
            $finalItemId,
            'повтор дописывает тот же пункт, а не создаёт новый'
        );
        self::assertSame(
            'akt.pdf — 31.08.2026 12:30 — https://disk/9077',
            $api->getChecklistItem(555, $finalItemId)['TITLE'],
            'после успешного повтора заголовок дополнен ссылкой, а не остаётся "голым"'
        );
        self::assertSame('0', $settings->get(ChecklistWriter::SETTING_KEY));
    }

    public function testHardRejectionOfAttachmentsFieldFallsBackWithoutCreatingItem(): void
    {
        // Снято на живом портале: task.checklistitem.add не молча игнорирует незнакомое ему
        // поле ATTACHMENTS, а отклоняет вызов целиком ошибкой wrong_arguments — пункт чек-листа
        // при этом не создаётся вовсе, "перечитать и проверить вложение" здесь невозможно.
        $this->api->throwOnChecklistAttachment = new B24ApiException(
            'param #1 (arFields) for method CTaskChecklistItem::add() must not contain key "attachments"',
            ''
        );

        $itemId = $this->write();

        self::assertSame('0', $this->settings->get(ChecklistWriter::SETTING_KEY));
        self::assertFalse($this->writer->attachmentsSupported());
        self::assertSame([9077], $this->api->attachedFiles[555]);
        self::assertSame(
            'akt.pdf — 31.08.2026 12:30 — https://disk/9077',
            $this->api->getChecklistItem(555, $itemId)['TITLE']
        );
        // Только корень чек-листа и один пункт файла — попытка с ATTACHMENTS не оставила
        // осиротевшего пункта, потому что addChecklistItem бросил исключение до создания.
        self::assertCount(2, $this->api->addedChecklistItems);
    }

    public function testTransientErrorDuringProbePropagatesWithoutTouchingFlag(): void
    {
        $this->api->throwOnChecklistAttachment = new B24ApiException('лимит запросов', 'QUERY_LIMIT_EXCEEDED');

        $this->expectException(B24ApiException::class);

        try {
            $this->write();
        } finally {
            self::assertNull(
                $this->settings->get(ChecklistWriter::SETTING_KEY),
                'временный сбой ничего не говорит о поддержке ATTACHMENTS — флаг не должен устанавливаться'
            );
        }
    }
}
