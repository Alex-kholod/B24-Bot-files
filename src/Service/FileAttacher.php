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
        // отдельный шаг "сохранить на Диск" (im.disk.file.save) не нужен. Но и
        // disk.file.get по тому же id на живом портале оказался ненадёжен: он
        // проверяет права на чтение у пользователя, чей OAuth-токен использует
        // приложение, а файлы открытой линии лежат в личной папке НАЗНАЧЕННОГО
        // ОПЕРАТОРА конкретного диалога — для произвольного набора очередей этих
        // прав у одного пользователя не бывает (ACCESS_DENIED). Ссылку на скачивание
        // получаем через бота (imbot.v2.File.download): он проверяет владение ботом,
        // а не Disk ACL, и работает независимо от того, кто оператор диалога.
        // Имени файла этот метод не возвращает — используем fallback, как и раньше
        // для файлов без имени.
        $diskFileId = $chatFileId;
        $downloadUrl = $this->api->getChatFileDownloadUrl($diskFileId);
        $name = $fallbackName !== '' ? $fallbackName : "file-{$diskFileId}";

        $this->writer->write(
            $clientKey,
            $taskId,
            $diskFileId,
            $name,
            $downloadUrl,
            $now,
            $pendingId
        );
    }
}
