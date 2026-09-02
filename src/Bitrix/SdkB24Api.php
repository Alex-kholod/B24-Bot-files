<?php

declare(strict_types=1);

namespace B24DocsBot\Bitrix;

use Bitrix24\SDK\Core\Exceptions\ItemNotFoundException;
use Bitrix24\SDK\Core\Exceptions\MethodNotFoundException;
use Bitrix24\SDK\Core\Exceptions\OperationTimeLimitExceededException;
use Bitrix24\SDK\Core\Exceptions\PortalUnavailableException;
use Bitrix24\SDK\Core\Exceptions\QueryLimitExceededException;
use Bitrix24\SDK\Core\Exceptions\TransportException;
use Bitrix24\SDK\Services\ServiceBuilder;
use Throwable;

/**
 * Реализация порта B24Api поверх bitrix24/b24phpsdk. Единственный класс проекта,
 * которому позволено знать о внутренностях SDK.
 *
 * Что зафиксировано по фактическому коду vendor/bitrix24/b24phpsdk (шаг 1 задачи 7):
 *
 * - `Bitrix24\SDK\Services\ServiceBuilder::$core` — публичное свойство типа
 *   `Bitrix24\SDK\Core\Contracts\CoreInterface`;
 *   `CoreInterface::call(string $apiMethod, array $parameters = [], ApiVersion $apiVersion = ApiVersion::v1): Response`.
 * - `Bitrix24\SDK\Core\Response\Response::getResponseData(): ResponseData`,
 *   `ResponseData::getResult(): array` — ровно та цепочка, что используется в
 *   официальных примерах документации. Скалярный `result` (например у
 *   `task.checklistitem.add`) SDK оборачивает в массив: `475` → `[475]`,
 *   поэтому такие ответы читаются как `$result[0]`.
 * - Ошибки уровня API преобразуются `Bitrix24\SDK\Core\ApiLevelErrorHandler` в
 *   типизированные наследники `Bitrix24\SDK\Core\Exceptions\BaseException`
 *   (QueryLimitExceededException, OperationTimeLimitExceededException,
 *   TransportException, ItemNotFoundException, MethodNotFoundException и др.),
 *   их и разбирает `extractErrorCode()`.
 *
 * Типизированные сервисы SDK, покрывающие часть наших вызовов, — их сигнатуры
 * взяты как источник истины по параметрам и форме ответа, но вызовы сделаны
 * через `core->call()`, чтобы весь порт был единообразным (см. шаг 3 брифа):
 *
 * - `Services\IMOpenLines\Session\Service\Session::getDialog()` → 'imopenlines.dialog.get', ['CHAT_ID' => …];
 *   результат — объект диалога напрямую.
 * - `Services\IM\Disk\Service\Disk::saveFile(int $fileId)` → 'im.disk.file.save', ['FILE_ID' => …];
 *   результат — ['folder' => …, 'file' => …], идентификатор файла Диска лежит в `file.id`
 *   (см. `Services\IM\Disk\Result\FileSaveResult::fileId()`). Параметра CHAT_ID у метода нет.
 * - `Services\IMBot\Bot\Service\Bot::register()` → 'imbot.v2.Bot.register', ['fields' => …];
 *   результат — ['bot' => …, 'users' => …], идентификатор в `bot.id`
 *   (см. `Services\IMBot\Bot\Result\BotResult`). Регистр имени метода значим и
 *   сохраняется `EndpointUrlFormatter` (метод есть в его списке case-sensitive).
 * - `Services\Task\Service\Task::get()` вызывает 'tasks.task.get' в ApiVersion::v3
 *   (`['id' => …]`, ответ в `result.item`). Мы работаем в версии v1 по умолчанию:
 *   параметр `taskId`, ответ в `result.task`; читаются оба ключа.
 * - `Services\Disk\File\Service\File` покрывает 'disk.file.get' (['id' => …]).
 * - `Services\Task\Checklistitem\Service\Checklistitem` покрывает
 *   'task.checklistitem.add|get|getlist|update' с теми же параметрами TASKID/ITEMID/FIELDS,
 *   но его `add(int $taskId, string $title, int $sort, bool $completed)` не принимает
 *   PARENT_ID и ATTACHMENTS, которые нужны боту, — поэтому вызываем core->call() напрямую.
 *   Его результат `Core\Result\AddedItemResult::getId()` читает `getResult()[0]`, что
 *   подтверждает разбор скалярного ответа `task.checklistitem.add` в `addChecklistItem()`.
 */
final class SdkB24Api implements B24Api
{
    private const CRM_GET_METHODS = [
        'LEAD' => 'crm.lead.get',
        'CONTACT' => 'crm.contact.get',
        'COMPANY' => 'crm.company.get',
        'DEAL' => 'crm.deal.get',
    ];

    public function __construct(private readonly ServiceBuilder $serviceBuilder)
    {
    }

    public function getOpenLineDialog(int $chatId): array
    {
        return $this->call('imopenlines.dialog.get', ['CHAT_ID' => $chatId]);
    }

    public function getCrmEntity(string $entityType, int $entityId): ?array
    {
        $method = self::CRM_GET_METHODS[$entityType] ?? null;

        if ($method === null) {
            return null;
        }

        try {
            $entity = $this->call($method, ['id' => $entityId]);
        } catch (B24ApiException $exception) {
            if ($exception->isTransient()) {
                throw $exception;
            }

            return null;
        }

        if ($entity === []) {
            return null;
        }

        // У лида и сделки заголовок в TITLE, у контакта и компании собираем из имени.
        if (!isset($entity['TITLE']) || (string) $entity['TITLE'] === '') {
            $entity['TITLE'] = trim(implode(' ', array_filter([
                (string) ($entity['LAST_NAME'] ?? ''),
                (string) ($entity['NAME'] ?? ''),
            ])));
        }

        return $entity;
    }

    public function getDiskFile(int $diskFileId): array
    {
        return $this->call('disk.file.get', ['id' => $diskFileId]);
    }

    public function getTask(int $taskId): ?array
    {
        try {
            $result = $this->call('tasks.task.get', ['taskId' => $taskId]);
        } catch (B24ApiException $exception) {
            if ($exception->isTransient()) {
                throw $exception;
            }

            return null;
        }

        $task = (array) ($result['task'] ?? $result['item'] ?? $result);

        if ($task === []) {
            return null;
        }

        return [
            'id' => (int) ($task['id'] ?? $task['ID'] ?? 0),
            'status' => (int) ($task['status'] ?? $task['STATUS'] ?? 0),
            'isDeleted' => ((string) ($task['zombie'] ?? $task['ZOMBIE'] ?? 'N')) === 'Y',
        ];
    }

    public function findTaskIdByCrmBinding(string $crmBinding, array $excludeStatuses): ?int
    {
        $result = $this->call('tasks.task.list', [
            'filter' => ['UF_CRM_TASK' => $crmBinding, '!STATUS' => array_values($excludeStatuses), 'ZOMBIE' => 'N'],
            'select' => ['ID'],
            'order' => ['ID' => 'DESC'],
        ]);

        $tasks = (array) ($result['tasks'] ?? $result['items'] ?? []);
        $first = $tasks[0] ?? null;

        if (!is_array($first)) {
            return null;
        }

        $id = (int) ($first['id'] ?? $first['ID'] ?? 0);

        return $id > 0 ? $id : null;
    }

    public function addTask(array $fields): int
    {
        $result = $this->call('tasks.task.add', ['fields' => $fields]);
        $task = (array) ($result['task'] ?? $result['item'] ?? []);

        $id = (int) ($task['id'] ?? $task['ID'] ?? 0);

        if ($id <= 0) {
            throw new B24ApiException('Задача не создана', 'ERROR_UNEXPECTED_ANSWER');
        }

        return $id;
    }

    public function attachFilesToTask(int $taskId, array $diskFileIds): void
    {
        // tasks.task.file.attach (REST 3.0, множественный fileIds) на части порталов
        // не существует ("api method not found") — REST 3.0 для задач включён не везде.
        // Используем старый, универсально доступный tasks.task.files.attach: он
        // прикрепляет только один файл за вызов (параметр fileId, не массив), поэтому
        // зовём его в цикле.
        foreach ($diskFileIds as $diskFileId) {
            $this->call('tasks.task.files.attach', ['taskId' => $taskId, 'fileId' => $diskFileId]);
        }
    }

    public function addChecklistItem(int $taskId, array $fields): int
    {
        $result = $this->call('task.checklistitem.add', ['TASKID' => $taskId, 'FIELDS' => $fields]);

        // Ответ метода — скалярный идентификатор, SDK оборачивает его в массив.
        $id = (int) ($result[0] ?? $result['ID'] ?? 0);

        if ($id <= 0) {
            throw new B24ApiException('Пункт чек-листа не создан', 'ERROR_UNEXPECTED_ANSWER');
        }

        return $id;
    }

    public function updateChecklistItem(int $taskId, int $itemId, array $fields): void
    {
        $this->call('task.checklistitem.update', ['TASKID' => $taskId, 'ITEMID' => $itemId, 'FIELDS' => $fields]);
    }

    public function getChecklistItem(int $taskId, int $itemId): array
    {
        return $this->call('task.checklistitem.get', ['TASKID' => $taskId, 'ITEMID' => $itemId]);
    }

    public function getChecklistItems(int $taskId): array
    {
        return array_values($this->call('task.checklistitem.getlist', ['TASKID' => $taskId]));
    }

    public function registerBot(array $fields): int
    {
        $result = $this->call('imbot.v2.Bot.register', ['fields' => $fields]);
        $bot = (array) ($result['bot'] ?? []);
        $botId = (int) ($bot['id'] ?? $bot['ID'] ?? 0);

        if ($botId <= 0) {
            throw new B24ApiException('Бот не зарегистрирован', 'ERROR_UNEXPECTED_ANSWER');
        }

        return $botId;
    }

    /**
     * @return array содержимое поля result ответа Битрикс24
     */
    private function call(string $method, array $params): array
    {
        try {
            return $this->serviceBuilder->core->call($method, $params)->getResponseData()->getResult();
        } catch (Throwable $exception) {
            throw new B24ApiException(
                sprintf('Ошибка вызова %s: %s', $method, $exception->getMessage()),
                $this->extractErrorCode($exception),
                $exception
            );
        }
    }

    private function extractErrorCode(Throwable $exception): string
    {
        $code = match (true) {
            $exception instanceof QueryLimitExceededException => 'QUERY_LIMIT_EXCEEDED',
            $exception instanceof OperationTimeLimitExceededException => 'OPERATION_TIME_LIMIT',
            $exception instanceof PortalUnavailableException => 'OVERLOAD_LIMIT',
            $exception instanceof TransportException => 'NETWORK_ERROR',
            $exception instanceof ItemNotFoundException => 'ERROR_NOT_FOUND',
            $exception instanceof MethodNotFoundException => 'ERROR_METHOD_NOT_FOUND',
            default => '',
        };

        if ($code !== '') {
            return $code;
        }

        // Прочие ошибки приходят как BaseException с текстом «код - описание».
        $message = $exception->getMessage();

        foreach (['QUERY_LIMIT_EXCEEDED', 'OPERATION_TIME_LIMIT', 'OVERLOAD_LIMIT', 'INTERNAL_SERVER_ERROR'] as $known) {
            if (stripos($message, $known) !== false) {
                return $known;
            }
        }

        return '';
    }
}
