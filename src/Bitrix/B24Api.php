<?php

declare(strict_types=1);

namespace B24DocsBot\Bitrix;

/**
 * Порт Битрикс24: единственная точка соприкосновения бизнес-логики с порталом.
 *
 * Вся логика приложения работает только через этот интерфейс и в юнит-тестах
 * подменяется двойником `B24DocsBot\Tests\Bitrix\FakeB24Api`. Реализация поверх
 * официального SDK — `SdkB24Api`.
 */
interface B24Api
{
    /** Данные чата открытой линии (imopenlines.dialog.get). */
    public function getOpenLineDialog(int $chatId): array;

    /**
     * Одноразовая ссылка для скачивания файла чата (imbot.v2.File.download).
     *
     * Метод работает от имени БОТА (проверяются права владения ботом), а не
     * пользователя, чей OAuth-токен использует приложение, — в отличие от
     * disk.file.get, который требует права на чтение объекта Диска: файл открытой
     * линии лежит в личной папке назначенного оператора диалога.
     */
    public function getChatFileDownloadUrl(int $fileId): string;

    /**
     * Добавляет в таймлайн сделки комментарий с файлами (crm.timeline.comment.add)
     * и возвращает его идентификатор.
     *
     * @param array<int, array{name: string, content: string}> $files
     */
    public function addDealTimelineComment(int $dealId, string $text, array $files): int;

    /**
     * Комментарий таймлайна или null, если его нет (удалён). Ключ files — файлы комментария
     * в виде [['id' => id файла на Диске, 'name' => имя], ...].
     *
     * @return array{files: array<int, array{id: int, name: string}>}|null
     */
    public function getTimelineComment(int $commentId): ?array;

    /** Подписанная ссылка на скачивание объекта Диска (disk.file.get, DOWNLOAD_URL). */
    public function getDiskFileDownloadUrl(int $diskFileId): string;

    /**
     * Заменяет файлы комментария итоговым набором (crm.timeline.comment.update).
     * Битрикс24 удаляет все файлы, которых нет в запросе, поэтому передавать надо старые и новые.
     *
     * @param array<int, array{name: string, content: string}> $files
     */
    public function updateTimelineCommentFiles(int $commentId, int $dealId, string $text, array $files): void;

    /** Закрепляет запись таймлайна сделки (crm.timeline.item.pin). */
    public function pinTimelineItem(int $itemId, int $dealId): void;

    /** Открепляет запись таймлайна сделки (crm.timeline.item.unpin). */
    public function unpinTimelineItem(int $itemId, int $dealId): void;

    /** Регистрирует бота и возвращает его идентификатор. */
    public function registerBot(array $fields): int;
}
