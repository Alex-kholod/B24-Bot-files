<?php

declare(strict_types=1);

namespace B24DocsBot\Service;

use B24DocsBot\Bitrix\B24Api;
use B24DocsBot\Bitrix\FileDownloader;
use DateTimeImmutable;

final class FileAttacher
{
    public function __construct(
        private readonly B24Api $api,
        private readonly ChecklistWriter $writer,
        private readonly FileDownloader $downloader,
    ) {
    }

    public function attach(
        string $clientKey,
        int $taskId,
        int $chatFileId,
        string $fallbackName,
        DateTimeImmutable $now
    ): void {
        // Файл открытой линии лежит в личной папке назначенного оператора диалога, и у
        // пользователя приложения прав на него нет (disk.file.get и tasks.task.files.attach
        // дают ACCESS_DENIED). Поэтому: одноразовая ссылка через бота -> скачиваем сразу
        // (она быстро протухает) -> кладём копию в хранилище приложения, где права есть.
        $url = $this->api->getChatFileDownloadUrl($chatFileId);
        $downloaded = $this->downloader->download($url, $fallbackName !== '' ? $fallbackName : "file-{$chatFileId}");
        $stored = $this->api->uploadFileToAppStorage($downloaded['name'], $downloaded['content']);

        $this->writer->write($clientKey, $taskId, $stored['id'], $stored['name'], $now);
    }
}
