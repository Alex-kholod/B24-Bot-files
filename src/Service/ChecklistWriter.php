<?php

declare(strict_types=1);

namespace B24DocsBot\Service;

use B24DocsBot\Bitrix\B24Api;
use B24DocsBot\Storage\SettingsRepository;
use B24DocsBot\Storage\TaskLinkRepository;
use DateTimeImmutable;

final class ChecklistWriter
{
    public const SETTING_KEY = 'checklist_attachments_supported';

    public function __construct(
        private readonly B24Api $api,
        private readonly SettingsRepository $settings,
        private readonly TaskLinkRepository $links,
        private readonly string $checklistTitle,
    ) {
    }

    public function attachmentsSupported(): ?bool
    {
        $stored = $this->settings->get(self::SETTING_KEY);

        return $stored === null ? null : $stored === '1';
    }

    public function write(
        string $clientKey,
        int $taskId,
        int $diskFileId,
        string $fileName,
        string $downloadUrl,
        DateTimeImmutable $now
    ): int {
        $rootId = $this->checklistRootId($clientKey, $taskId);
        $label = sprintf('%s — %s', $fileName, $now->format('d.m.Y H:i'));

        $supported = $this->attachmentsSupported();

        if ($supported === null) {
            return $this->probeAndWrite($taskId, $rootId, $label, $diskFileId, $downloadUrl);
        }

        return $supported
            ? $this->writeWithAttachment($taskId, $rootId, $label, $diskFileId)
            : $this->writeWithLink($taskId, $rootId, $label, $diskFileId, $downloadUrl);
    }

    private function probeAndWrite(
        int $taskId,
        int $rootId,
        string $label,
        int $diskFileId,
        string $downloadUrl
    ): int {
        $itemId = $this->writeWithAttachment($taskId, $rootId, $label, $diskFileId);
        $item = $this->api->getChecklistItem($taskId, $itemId);

        if ($this->hasAttachment($item, $diskFileId)) {
            $this->settings->set(self::SETTING_KEY, '1');

            return $itemId;
        }

        $this->settings->set(self::SETTING_KEY, '0');
        $this->api->attachFilesToTask($taskId, [$diskFileId]);
        $this->api->updateChecklistItem($taskId, $itemId, ['TITLE' => $label . ' — ' . $downloadUrl]);

        return $itemId;
    }

    private function writeWithAttachment(int $taskId, int $rootId, string $label, int $diskFileId): int
    {
        return $this->api->addChecklistItem($taskId, [
            'TITLE' => $label,
            'PARENT_ID' => $rootId,
            'ATTACHMENTS' => [$diskFileId],
        ]);
    }

    private function writeWithLink(
        int $taskId,
        int $rootId,
        string $label,
        int $diskFileId,
        string $downloadUrl
    ): int {
        $this->api->attachFilesToTask($taskId, [$diskFileId]);

        return $this->api->addChecklistItem($taskId, [
            'TITLE' => $label . ' — ' . $downloadUrl,
            'PARENT_ID' => $rootId,
        ]);
    }

    private function hasAttachment(array $item, int $diskFileId): bool
    {
        foreach ($item['ATTACHMENTS'] ?? [] as $attachment) {
            if ((int) ($attachment['FILE_ID'] ?? 0) === $diskFileId) {
                return true;
            }
        }

        return false;
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
