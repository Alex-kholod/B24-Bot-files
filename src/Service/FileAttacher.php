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
        int $chatFileId,
        string $fallbackName,
        DateTimeImmutable $now,
        int $pendingId
    ): void {
        // Файл чата в Битрикс24 уже является объектом Диска с момента загрузки —
        // отдельный шаг "сохранить на Диск" (im.disk.file.save) не нужен и на живом
        // портале оказался ненадёжен: он копирует файл в личную папку ВЫЗЫВАЮЩЕГО
        // пользователя и требует, чтобы этот пользователь был участником чата, а для
        // файлов от анонимных отправителей через внешние коннекторы (Telegram и т.п.)
        // не срабатывает вовсе ("File ID can't be saved"). chat_file_id из события —
        // тот же самый id, что принимает disk.file.get, проверено на живом портале.
        $diskFileId = $chatFileId;
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
            $now,
            $pendingId
        );
    }
}
