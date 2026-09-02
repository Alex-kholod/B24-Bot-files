<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Service;

use B24DocsBot\Bitrix\B24ApiException;
use B24DocsBot\Service\ChecklistWriter;
use B24DocsBot\Service\FileAttacher;
use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\SettingsRepository;
use B24DocsBot\Storage\TaskLinkRepository;
use B24DocsBot\Tests\Bitrix\FakeB24Api;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FileAttacherTest extends TestCase
{
    private FakeB24Api $api;
    private FileAttacher $attacher;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $db->migrate();

        $this->api = new FakeB24Api();
        $links = new TaskLinkRepository($db->pdo());
        $links->save('crm:CONTACT:123', 'CONTACT', 123, 555);

        $writer = new ChecklistWriter($this->api, new SettingsRepository($db->pdo()), $links, 'Документы от клиента');
        $this->attacher = new FileAttacher($this->api, $writer);
        $this->now = new DateTimeImmutable('2026-08-31 12:30:00');
    }

    public function testUsesChatFileIdDirectlyAsDiskFileIdThenWritesChecklistItem(): void
    {
        // chat_file_id из события — уже готовый id объекта Диска, отдельного шага
        // "сохранить на Диск" не требуется (см. комментарий в FileAttacher::attach).
        $this->attacher->attach('crm:CONTACT:123', 555, 77, '', $this->now, 1);

        self::assertSame([77], $this->api->fetchedDiskFiles);

        $item = end($this->api->addedChecklistItems)[1];
        self::assertStringContainsString('file-77.pdf', $item['TITLE']);
    }

    public function testUsesFallbackNameWhenDiskFileHasNoName(): void
    {
        $api = new class extends FakeB24Api {
            public function getDiskFile(int $diskFileId): array
            {
                return ['ID' => $diskFileId, 'NAME' => '', 'DOWNLOAD_URL' => ''];
            }
        };

        $db = new Database(':memory:');
        $db->migrate();
        $links = new TaskLinkRepository($db->pdo());
        $links->save('crm:CONTACT:123', 'CONTACT', 123, 555);

        $attacher = new FileAttacher(
            $api,
            new ChecklistWriter($api, new SettingsRepository($db->pdo()), $links, 'Документы от клиента')
        );

        $attacher->attach('crm:CONTACT:123', 555, 77, 'скан.jpg', $this->now, 1);

        $item = end($api->addedChecklistItems)[1];
        self::assertStringContainsString('скан.jpg', $item['TITLE']);
    }

    public function testPropagatesApiException(): void
    {
        $this->api->throwOnGetDiskFile = new B24ApiException('лимит', 'QUERY_LIMIT_EXCEEDED');

        $this->expectException(B24ApiException::class);

        $this->attacher->attach('crm:CONTACT:123', 555, 77, '', $this->now, 1);
    }

    public function testUsesSyntheticNameWhenBothNamesAreEmpty(): void
    {
        $api = new class extends FakeB24Api {
            public function getDiskFile(int $diskFileId): array
            {
                return ['ID' => $diskFileId, 'NAME' => '', 'DOWNLOAD_URL' => ''];
            }
        };

        $db = new Database(':memory:');
        $db->migrate();
        $links = new TaskLinkRepository($db->pdo());
        $links->save('crm:CONTACT:123', 'CONTACT', 123, 555);

        $attacher = new FileAttacher(
            $api,
            new ChecklistWriter($api, new SettingsRepository($db->pdo()), $links, 'Документы от клиента')
        );

        $attacher->attach('crm:CONTACT:123', 555, 77, '', $this->now, 1);

        $item = end($api->addedChecklistItems)[1];
        self::assertStringContainsString('file-77', $item['TITLE']);
    }

    public function testTrimsWhitespaceFromDiskName(): void
    {
        $api = new class extends FakeB24Api {
            public function getDiskFile(int $diskFileId): array
            {
                return ['ID' => $diskFileId, 'NAME' => '   ', 'DOWNLOAD_URL' => ''];
            }
        };

        $db = new Database(':memory:');
        $db->migrate();
        $links = new TaskLinkRepository($db->pdo());
        $links->save('crm:CONTACT:123', 'CONTACT', 123, 555);

        $attacher = new FileAttacher(
            $api,
            new ChecklistWriter($api, new SettingsRepository($db->pdo()), $links, 'Документы от клиента')
        );

        $attacher->attach('crm:CONTACT:123', 555, 77, 'важный_документ.pdf', $this->now, 1);

        $item = end($api->addedChecklistItems)[1];
        self::assertStringContainsString('важный_документ.pdf', $item['TITLE']);
    }
}
