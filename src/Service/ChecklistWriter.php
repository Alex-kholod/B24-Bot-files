<?php

declare(strict_types=1);

namespace B24DocsBot\Service;

use B24DocsBot\Bitrix\B24Api;
use B24DocsBot\Storage\TaskLinkRepository;
use DateTimeImmutable;

final class ChecklistWriter
{
    public function __construct(
        private readonly B24Api $api,
        private readonly TaskLinkRepository $links,
        private readonly string $checklistTitle,
    ) {
    }

    /**
     * Файл прикрепляется к самой задаче (REST Битрикс24 не умеет вкладывать файлы в пункты
     * чек-листа реальной задачи), а пункт содержит дату и гиперссылку с именем файла.
     */
    public function write(
        string $clientKey,
        int $taskId,
        int $diskFileId,
        string $fileName,
        DateTimeImmutable $now
    ): int {
        $rootId = $this->checklistRootId($clientKey, $taskId);

        $fileUrl = $this->api->attachFileToTask($taskId, $diskFileId);

        return $this->api->addChecklistItem($taskId, [
            'TITLE' => self::title($now, $fileName, $fileUrl),
            'PARENT_ID' => $rootId,
        ]);
    }

    public static function title(DateTimeImmutable $now, string $fileName, string $fileUrl): string
    {
        $date = $now->format('d.m.Y H:i');
        $text = str_replace(['[', ']'], '', $fileName);

        if ($fileUrl === '' || $text === '') {
            return $text === '' ? $date : "{$date} — {$text}";
        }

        return "{$date} — [URL={$fileUrl}]{$text}[/URL]";
    }

    private function checklistRootId(string $clientKey, int $taskId): int
    {
        $link = $this->links->find($clientKey);
        $cachedId = (int) ($link['checklist_id'] ?? 0);

        if ($cachedId > 0 && $this->rootExists($taskId, $cachedId)) {
            return $cachedId;
        }

        $rootId = $this->api->addChecklistItem($taskId, [
            'TITLE' => $this->checklistTitle,
            'PARENT_ID' => 0,
        ]);

        $this->links->setChecklistId($clientKey, $rootId);

        return $rootId;
    }

    private function rootExists(int $taskId, int $rootId): bool
    {
        foreach ($this->api->getChecklistItems($taskId) as $item) {
            if ((int) ($item['ID'] ?? 0) === $rootId) {
                return true;
            }
        }

        return false;
    }
}
