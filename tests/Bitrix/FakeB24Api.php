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
    public array $comments = [];             // commentId => [dealId, text, fileName, fileContent]
    public array $pinned = [];               // [dealId, commentId], в порядке закрепления
    public array $unpinned = [];             // [dealId, commentId]
    public ?B24ApiException $throwOnDialog = null;
    public ?B24ApiException $throwOnDownloadUrl = null;
    public ?B24ApiException $throwOnPin = null;
    public ?B24ApiException $throwOnUnpin = null;
    private int $nextId = 1000;

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

        return "https://disk/{$fileId}";
    }

    public function addDealTimelineComment(int $dealId, string $text, string $fileName, string $fileContent): int
    {
        $id = ++$this->nextId;
        $this->comments[$id] = [$dealId, $text, $fileName, $fileContent];

        return $id;
    }

    public function pinTimelineItem(int $itemId, int $dealId): void
    {
        if ($this->throwOnPin !== null) {
            throw $this->throwOnPin;
        }

        $this->pinned[] = [$dealId, $itemId];
    }

    public function unpinTimelineItem(int $itemId, int $dealId): void
    {
        if ($this->throwOnUnpin !== null) {
            throw $this->throwOnUnpin;
        }

        $this->unpinned[] = [$dealId, $itemId];
    }

    public function registerBot(array $fields): int
    {
        return 456;
    }
}
