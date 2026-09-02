<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Bitrix;

use B24DocsBot\Bitrix\B24Api;
use B24DocsBot\Bitrix\B24ApiException;

// Намеренно не final: тесты задач 11 и 12 наследуют этот двойник, переопределяя отдельные методы.
class FakeB24Api implements B24Api
{
    public array $dialogs = [];              // chatId => массив данных диалога
    public array $crmEntities = [];          // "CONTACT:123" => массив полей
    public array $tasks = [];                // taskId => массив полей
    public array $checklistItems = [];       // taskId => [itemId => поля]
    public ?int $findTaskResult = null;
    public bool $checklistAcceptsAttachments = true;
    public array $addedTasks = [];
    public array $attachedFiles = [];        // taskId => [diskFileId, ...]
    public array $addedChecklistItems = [];  // [taskId, fields]
    public array $fetchedDiskFiles = [];     // diskFileId, ...
    public ?B24ApiException $throwOnGetDiskFile = null;
    public ?B24ApiException $throwOnDialog = null;
    public ?B24ApiException $throwOnChecklistAttachment = null;
    private int $nextId = 1000;

    public function getOpenLineDialog(int $chatId): array
    {
        if ($this->throwOnDialog !== null) {
            throw $this->throwOnDialog;
        }

        return $this->dialogs[$chatId] ?? [];
    }

    public function getCrmEntity(string $entityType, int $entityId): ?array
    {
        return $this->crmEntities["{$entityType}:{$entityId}"] ?? null;
    }

    public function getDiskFile(int $diskFileId): array
    {
        $this->fetchedDiskFiles[] = $diskFileId;

        if ($this->throwOnGetDiskFile !== null) {
            throw $this->throwOnGetDiskFile;
        }

        return ['ID' => $diskFileId, 'NAME' => "file-{$diskFileId}.pdf", 'DOWNLOAD_URL' => "https://disk/{$diskFileId}"];
    }

    public function getTask(int $taskId): ?array
    {
        return $this->tasks[$taskId] ?? null;
    }

    public function findTaskIdByCrmBinding(string $crmBinding, array $excludeStatuses): ?int
    {
        return $this->findTaskResult;
    }

    public function addTask(array $fields): int
    {
        $id = ++$this->nextId;
        $this->addedTasks[] = $fields;
        $this->tasks[$id] = ['id' => $id, 'status' => 2, 'isDeleted' => false];

        return $id;
    }

    public function attachFilesToTask(int $taskId, array $diskFileIds): void
    {
        foreach ($diskFileIds as $fileId) {
            $this->attachedFiles[$taskId][] = $fileId;
        }
    }

    public function addChecklistItem(int $taskId, array $fields): int
    {
        if ($this->throwOnChecklistAttachment !== null && isset($fields['ATTACHMENTS'])) {
            throw $this->throwOnChecklistAttachment;
        }

        $id = ++$this->nextId;
        $this->addedChecklistItems[] = [$taskId, $fields];

        $stored = ['ID' => $id, 'TITLE' => (string) ($fields['TITLE'] ?? ''), 'PARENT_ID' => (int) ($fields['PARENT_ID'] ?? 0)];
        $stored['ATTACHMENTS'] = $this->checklistAcceptsAttachments && isset($fields['ATTACHMENTS'])
            ? array_map(static fn ($fileId): array => ['FILE_ID' => $fileId], (array) $fields['ATTACHMENTS'])
            : [];

        $this->checklistItems[$taskId][$id] = $stored;

        return $id;
    }

    public function updateChecklistItem(int $taskId, int $itemId, array $fields): void
    {
        if (!isset($this->checklistItems[$taskId][$itemId])) {
            return;
        }

        if (isset($fields['TITLE'])) {
            $this->checklistItems[$taskId][$itemId]['TITLE'] = (string) $fields['TITLE'];
        }
    }

    public function getChecklistItem(int $taskId, int $itemId): array
    {
        return $this->checklistItems[$taskId][$itemId] ?? [];
    }

    public function getChecklistItems(int $taskId): array
    {
        return array_values($this->checklistItems[$taskId] ?? []);
    }

    public function registerBot(array $fields): int
    {
        return 456;
    }
}
