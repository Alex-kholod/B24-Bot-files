<?php

declare(strict_types=1);

namespace B24DocsBot\Service;

use B24DocsBot\Bitrix\B24Api;
use DateTimeImmutable;

final class FileAttacher
{
    public function __construct(
        private readonly B24Api $api,
        private readonly ChecklistWriter $writer,
    ) {
    }

    public function attach(
        string $clientKey,
        int $taskId,
        int $chatId,
        int $chatFileId,
        string $fallbackName,
        DateTimeImmutable $now
    ): void {
        $diskFileId = $this->api->saveChatFileToDisk($chatId, $chatFileId);
        $file = $this->api->getDiskFile($diskFileId);

        $name = trim((string) ($file['NAME'] ?? ''));
        if ($name === '') {
            $name = $fallbackName !== '' ? $fallbackName : "file-{$diskFileId}";
        }

        $this->writer->write(
            $clientKey,
            $taskId,
            $diskFileId,
            $name,
            (string) ($file['DOWNLOAD_URL'] ?? ''),
            $now
        );
    }
}
