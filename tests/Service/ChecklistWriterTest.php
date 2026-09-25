<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Service;

use B24DocsBot\Bitrix\B24ApiException;
use B24DocsBot\Service\ChecklistWriter;
use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\TaskLinkRepository;
use B24DocsBot\Tests\Bitrix\FakeB24Api;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ChecklistWriterTest extends TestCase
{
    private FakeB24Api $api;
    private TaskLinkRepository $links;
    private ChecklistWriter $writer;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $db->migrate();

        $this->api = new FakeB24Api();
        $this->links = new TaskLinkRepository($db->pdo());
        $this->links->save('crm:CONTACT:123', 'CONTACT', 123, 555);
        $this->writer = new ChecklistWriter($this->api, $this->links, 'Документы от клиента');
        $this->now = new DateTimeImmutable('2026-08-31 12:30:00');
    }

    private function write(): int
    {
        return $this->writer->write('crm:CONTACT:123', 555, 9077, 'akt.pdf', $this->now);
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

    public function testRecreatesRootWhenCachedOneWasDeleted(): void
    {
        $this->links->setChecklistId('crm:CONTACT:123', 424242);

        $this->write();

        self::assertNotSame(424242, $this->links->find('crm:CONTACT:123')['checklist_id']);
    }

    public function testAttachesFileToTaskAndWritesDateWithHyperlink(): void
    {
        $this->write();
        $item = end($this->api->addedChecklistItems)[1];

        self::assertSame([9077], $this->api->attachedFiles[555]);
        self::assertSame('31.08.2026 12:30 — [URL=https://portal/attached/9077]akt.pdf[/URL]', $item['TITLE']);
        self::assertGreaterThan(0, $item['PARENT_ID']);
    }

    public function testBracketsInFileNameCannotBreakBbCode(): void
    {
        $title = ChecklistWriter::title($this->now, 'a[b]c.pdf', 'https://x/1');

        self::assertSame('31.08.2026 12:30 — [URL=https://x/1]abc.pdf[/URL]', $title);
    }

    public function testTitleWithoutUrlFallsBackToPlainName(): void
    {
        self::assertSame('31.08.2026 12:30 — akt.pdf', ChecklistWriter::title($this->now, 'akt.pdf', ''));
    }

    public function testAttachFailurePropagatesSoRowIsRetried(): void
    {
        $this->api->throwOnAttachFilesToTask = new B24ApiException('access denied', 'ACCESS_DENIED');

        $this->expectException(B24ApiException::class);

        $this->write();
    }
}
