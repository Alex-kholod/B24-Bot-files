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
 * Все вызовы идут через `ServiceBuilder::$core->call()`: ответ — `ResponseData::getResult()`
 * (массив). Скалярный `result` (например идентификатор у `crm.timeline.comment.add`) и `null`
 * (у `crm.timeline.item.pin`) SDK оборачивает в массив: `999` → `[999]`, поэтому скаляры
 * читаются как `$result[0]`. Ошибки уровня API превращаются в типизированные исключения SDK,
 * которые `extractErrorCode()` приводит к кодам `B24ApiException`.
 *
 * Регистр имён методов `imbot.v2.*` значим и сохраняется `EndpointUrlFormatter`.
 */
final class SdkB24Api implements B24Api
{
    private const DEAL_OWNER_TYPE_ID = 2;

    public function __construct(
        private readonly ServiceBuilder $serviceBuilder,
        private readonly int $botId,
    ) {
    }

    public function getOpenLineDialog(int $chatId): array
    {
        return $this->call('imopenlines.dialog.get', ['CHAT_ID' => $chatId]);
    }

    public function getChatFileDownloadUrl(int $fileId): string
    {
        // disk.file.get здесь не подходит: он проверяет права на чтение конкретного
        // объекта Диска у пользователя, чей OAuth-токен использует приложение, а файлы
        // открытых линий лежат в личной папке назначенного оператора диалога — на
        // живом портале это дало ACCESS_DENIED для файлов из очередей/диалогов, где
        // этот пользователь не является оператором (см. журнал изменений). Метод бота
        // проверяет владение ботом, а не Disk ACL конкретного пользователя.
        $result = $this->call('imbot.v2.File.download', ['botId' => $this->botId, 'fileId' => $fileId]);

        return (string) ($result['downloadUrl'] ?? '');
    }

    public function addDealTimelineComment(int $dealId, string $text, array $files): int
    {
        $result = $this->call('crm.timeline.comment.add', ['fields' => [
            'ENTITY_ID' => $dealId,
            'ENTITY_TYPE' => 'deal',
            'COMMENT' => $text,
            'FILES' => self::encodeFiles($files),
        ]]);

        // Ответ метода — скалярный идентификатор, SDK оборачивает его в массив.
        $id = (int) ($result[0] ?? 0);

        if ($id <= 0) {
            throw new B24ApiException('Комментарий в таймлайн не добавлен', 'ERROR_UNEXPECTED_ANSWER');
        }

        return $id;
    }

    public function getTimelineComment(int $commentId): ?array
    {
        try {
            $comment = $this->call('crm.timeline.comment.get', ['id' => $commentId]);
        } catch (B24ApiException $exception) {
            // Битрикс24 отвечает на удалённый комментарий пустым кодом и текстом "Not found.".
            if (!$exception->isTransient() && stripos($exception->getMessage(), 'not found') !== false) {
                return null;
            }

            throw $exception;
        }

        if ($comment === []) {
            return null;
        }

        $files = [];

        foreach ((array) ($comment['FILES'] ?? []) as $key => $file) {
            $id = (int) ($file['id'] ?? $key);

            if ($id > 0) {
                $files[] = ['id' => $id, 'name' => (string) ($file['name'] ?? "file-{$id}")];
            }
        }

        return ['files' => $files];
    }

    public function getDiskFileDownloadUrl(int $diskFileId): string
    {
        $file = $this->call('disk.file.get', ['id' => $diskFileId]);

        return (string) ($file['DOWNLOAD_URL'] ?? '');
    }

    public function updateTimelineCommentFiles(int $commentId, int $dealId, string $text, array $files): void
    {
        $this->call('crm.timeline.comment.update', [
            'id' => $commentId,
            'ownerTypeId' => self::DEAL_OWNER_TYPE_ID,
            'ownerId' => $dealId,
            'fields' => ['COMMENT' => $text, 'FILES' => self::encodeFiles($files)],
        ]);
    }

    public function pinTimelineItem(int $itemId, int $dealId): void
    {
        $this->call('crm.timeline.item.pin', [
            'id' => $itemId,
            'ownerTypeId' => self::DEAL_OWNER_TYPE_ID,
            'ownerId' => $dealId,
        ]);
    }

    public function unpinTimelineItem(int $itemId, int $dealId): void
    {
        $this->call('crm.timeline.item.unpin', [
            'id' => $itemId,
            'ownerTypeId' => self::DEAL_OWNER_TYPE_ID,
            'ownerId' => $dealId,
        ]);
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

    /** @param array<int, array{name: string, content: string}> $files */
    private static function encodeFiles(array $files): array
    {
        return array_map(
            static fn (array $file): array => [$file['name'], base64_encode($file['content'])],
            array_values($files)
        );
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
