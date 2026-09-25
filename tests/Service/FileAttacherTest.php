<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Service;

use B24DocsBot\Bitrix\B24ApiException;
use B24DocsBot\Service\ChecklistWriter;
use B24DocsBot\Service\FileAttacher;
use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\TaskLinkRepository;
use B24DocsBot\Tests\Bitrix\FakeB24Api;
use B24DocsBot\Tests\Bitrix\FakeFileDownloader;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FileAttacherTest extends TestCase
{
    private FakeB24Api $api;
    private FakeFileDownloader $downloader;
    private FileAttacher $attacher;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $db->migrate();

        $this->api = new FakeB24Api();
        $this->downloader = new FakeFileDownloader();
        $links = new TaskLinkRepository($db->pdo());
        $links->save('crm:CONTACT:123', 'CONTACT', 123, 555);

        $this->attacher = new FileAttacher(
            $this->api,
            new ChecklistWriter($this->api, $links, 'Документы от клиента'),
            $this->downloader
        );
        $this->now = new DateTimeImmutable('2026-08-31 12:30:00');
    }

    public function testDownloadsCopiesToAppStorageAttachesToTaskAndWritesLinkItem(): void
    {
        $this->downloader->name = 'Требования к дому.pdf';

        $this->attacher->attach('crm:CONTACT:123', 555, 77, '', $this->now);

        self::assertSame([77], $this->api->fetchedDiskFiles);
        self::assertSame(['https://disk/77'], $this->downloader->urls);
        self::assertSame([['Требования к дому.pdf', 'BODY']], $this->api->uploadedFiles);
        self::assertSame([9001], $this->api->attachedFiles[555]);

        $item = end($this->api->addedChecklistItems)[1];
        self::assertSame('31.08.2026 12:30 — [URL=https://portal/attached/9001]Требования к дому.pdf[/URL]', $item['TITLE']);
        self::assertArrayNotHasKey('ATTACHMENTS', $item);
    }

    public function testUsesSyntheticNameWhenNothingKnown(): void
    {
        $this->attacher->attach('crm:CONTACT:123', 555, 77, '', $this->now);

        self::assertSame('file-77', $this->api->uploadedFiles[0][0]);
    }

    public function testPropagatesDownloadFailureWithoutTouchingTask(): void
    {
        $this->downloader->throw = new B24ApiException('протухла ссылка', '');

        try {
            $this->attacher->attach('crm:CONTACT:123', 555, 77, '', $this->now);
            self::fail('ожидалось исключение');
        } catch (B24ApiException) {
        }

        self::assertSame([], $this->api->uploadedFiles);
        self::assertSame([], $this->api->addedChecklistItems);
    }

    public function testPropagatesApiException(): void
    {
        $this->api->throwOnGetDiskFile = new B24ApiException('лимит', 'QUERY_LIMIT_EXCEEDED');

        $this->expectException(B24ApiException::class);

        $this->attacher->attach('crm:CONTACT:123', 555, 77, '', $this->now);
    }
}
