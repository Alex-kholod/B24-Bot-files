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
    public array $addedTasks = [];
    public array $attachedFiles = [];        // taskId => [diskFileId, ...]
    public array $addedChecklistItems = [];  // [taskId, fields]
    public array $fetchedDiskFiles = [];     // diskFileId, ...
    public ?B24ApiException $throwOnGetDiskFile = null;
    public ?B24ApiException $throwOnAttachFilesToTask = null;
    public array $uploadedFiles = [];        // [name, content]
    public ?B24ApiException $throwOnDialog = null;
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

    public function getChatFileDownloadUrl(int $fileId): string
    {
        $this->fetchedDiskFiles[] = $fileId;

        if ($this->throwOnGetDiskFile !== null) {
            throw $this->throwOnGetDiskFile;
        }

        return "https://disk/{$fileId}";
    }

    public function uploadFileToAppStorage(string $name, string $content): array
    {
        $this->uploadedFiles[] = [$name, $content];
        $id = 9000 + count($this->uploadedFiles);

        return ['id' => $id, 'name' => $name];
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

    public function attachFileToTask(int $taskId, int $diskFileId): string
    {
        if ($this->throwOnAttachFilesToTask !== null) {
            throw $this->throwOnAttachFilesToTask;
        }

        $this->attachedFiles[$taskId][] = $diskFileId;

        return "https://portal/attached/{$diskFileId}";
    }

    public function addChecklistItem(int $taskId, array $fields): int
    {
        $id = ++$this->nextId;
        $this->addedChecklistItems[] = [$taskId, $fields];

        $this->checklistItems[$taskId][$id] = [
            'ID' => $id,
            'TITLE' => (string) ($fields['TITLE'] ?? ''),
            'PARENT_ID' => (int) ($fields['PARENT_ID'] ?? 0),
        ];

        return $id;
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
