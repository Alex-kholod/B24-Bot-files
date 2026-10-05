<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Bitrix;

use B24DocsBot\Bitrix\B24Api;
use B24DocsBot\Bitrix\B24ApiException;

// Намеренно не final: тесты наследуют этот двойник, переопределяя отдельные методы.
class FakeB24Api implements B24Api
{
    public array $dialogs = [];              // chatId => массив данных диалога
    public array $fetchedFiles = [];         // chatFileId, ...
    public array $comments = [];             // commentId => [dealId, text, files [['id','name','content']]]
    public array $pinned = [];               // [dealId, commentId]
    public array $updates = [];              // [commentId, dealId, text, files]
    public ?B24ApiException $throwOnDialog = null;
    public ?B24ApiException $throwOnDownloadUrl = null;
    public ?B24ApiException $throwOnPin = null;
    public ?B24ApiException $throwOnDiskUrl = null;
    public ?B24ApiException $throwOnUpdate = null;
    private int $nextId = 1000;
    private int $nextFileId = 7000;

    public function getOpenLineDialog(int $chatId): array
    {
        if ($this->throwOnDialog !== null) {
            throw $this->throwOnDialog;
        }

        return $this->dialogs[$chatId] ?? [];
    }

    public function getChatFileDownloadUrl(int $fileId): string
    {
        $this->fetchedFiles[] = $fileId;

        if ($this->throwOnDownloadUrl !== null) {
            throw $this->throwOnDownloadUrl;
        }

        return "https://chat/{$fileId}";
    }

    public function addDealTimelineComment(int $dealId, string $text, array $files): int
    {
        $id = ++$this->nextId;
        $this->comments[$id] = [$dealId, $text, $this->store($files)];

        return $id;
    }

    public function getTimelineComment(int $commentId): ?array
    {
        if (!isset($this->comments[$commentId])) {
            return null;
        }

        return ['files' => array_map(
            static fn (array $f): array => ['id' => $f['id'], 'name' => $f['name']],
            $this->comments[$commentId][2]
        )];
    }

    public function getDiskFileDownloadUrl(int $diskFileId): string
    {
        if ($this->throwOnDiskUrl !== null) {
            throw $this->throwOnDiskUrl;
        }

        return "https://disk/{$diskFileId}";
    }

    public function updateTimelineCommentFiles(int $commentId, int $dealId, string $text, array $files): void
    {
        if ($this->throwOnUpdate !== null) {
            throw $this->throwOnUpdate;
        }

        $this->updates[] = [$commentId, $dealId, $text, $files];
        $this->comments[$commentId] = [$dealId, $text, $this->store($files)];
    }

    public function pinTimelineItem(int $itemId, int $dealId): void
    {
        if ($this->throwOnPin !== null) {
            throw $this->throwOnPin;
        }

        $this->pinned[] = [$dealId, $itemId];
    }

    public function registerBot(array $fields): int
    {
        return 456;
    }

    /** Как портал: каждый загруженный файл получает новый id на Диске. */
    private function store(array $files): array
    {
        return array_map(function (array $file): array {
            return ['id' => ++$this->nextFileId, 'name' => $file['name'], 'content' => $file['content']];
        }, array_values($files));
    }
}
