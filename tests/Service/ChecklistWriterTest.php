<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Service;

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

    private function write(): int
    {
        return $this->writer->write('crm:CONTACT:123', 555, 9077, 'akt.pdf', 'https://disk/9077', $this->now);
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
}
