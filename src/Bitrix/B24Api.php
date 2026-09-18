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

    /** Элемент CRM или null, если не найден. Возвращает как минимум ключи ID, TITLE, ASSIGNED_BY_ID. */
    public function getCrmEntity(string $entityType, int $entityId): ?array;

    /**
     * Одноразовая ссылка для скачивания файла чата (imbot.v2.File.download).
     *
     * Метод работает от имени БОТА (проверяются права владения ботом), а не
     * пользователя, чей OAuth-токен использует приложение, — в отличие от
     * disk.file.get и tasks.task.files.attach, которые требуют права на чтение
     * конкретного объекта Диска у этого пользователя. На практике файл открытой
     * линии лежит в личной папке Диска НАЗНАЧЕННОГО ОПЕРАТОРА этого диалога, и
     * получить туда доступ для произвольного набора операторов/очередей не
     * получится — поэтому для скачивания используется путь через бота.
     */
    public function getChatFileDownloadUrl(int $fileId): string;

    /**
     * Задача или null, если не найдена.
     * Реализация обязана нормализовать ответ к трём ключам: int id, int status, bool isDeleted.
     */
    public function getTask(int $taskId): ?array;

    /**
     * Идентификатор самой свежей задачи с указанной CRM-привязкой (например, "C_123"),
     * исключая задачи в перечисленных статусах.
     *
     * @param int[] $excludeStatuses
     */
    public function findTaskIdByCrmBinding(string $crmBinding, array $excludeStatuses): ?int;

    public function addTask(array $fields): int;

    /** @param int[] $diskFileIds */
    public function attachFilesToTask(int $taskId, array $diskFileIds): void;

    public function addChecklistItem(int $taskId, array $fields): int;

    public function updateChecklistItem(int $taskId, int $itemId, array $fields): void;

    public function getChecklistItem(int $taskId, int $itemId): array;

    /** @return array<int, array> список пунктов чек-листа задачи */
    public function getChecklistItems(int $taskId): array;

    /** Регистрирует бота и возвращает его идентификатор. */
    public function registerBot(array $fields): int;
}
