<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Bitrix;

use B24DocsBot\Bitrix\B24ApiException;
use B24DocsBot\Bitrix\SdkB24Api;
use Bitrix24\SDK\Core\Contracts\CoreInterface;
use Bitrix24\SDK\Core\Exceptions\ItemNotFoundException;
use Bitrix24\SDK\Core\Exceptions\QueryLimitExceededException;
use Bitrix24\SDK\Core\Response\DTO\ResponseData;
use Bitrix24\SDK\Core\Response\Response;
use Bitrix24\SDK\Services\ServiceBuilder;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Проверяется только чистая логика нормализации ответов: сетевых вызовов нет,
 * `core` подменён заглушкой. Полноценная проверка на живом портале — задача 16.
 */
final class SdkB24ApiTest extends TestCase
{
    public function testGetTaskNormalisesResponseToThreeKeys(): void
    {
        $api = $this->apiReturning(['task' => ['id' => '42', 'status' => '5', 'zombie' => 'N', 'title' => 'x']]);

        self::assertSame(['id' => 42, 'status' => 5, 'isDeleted' => false], $api->getTask(42));
    }

    public function testGetTaskReadsUppercaseKeysAndZombieFlag(): void
    {
        $api = $this->apiReturning(['task' => ['ID' => 7, 'STATUS' => 2, 'ZOMBIE' => 'Y']]);

        self::assertSame(['id' => 7, 'status' => 2, 'isDeleted' => true], $api->getTask(7));
    }

    public function testGetTaskReturnsNullWhenTaskNotFound(): void
    {
        $api = $this->apiThrowing(new ItemNotFoundException('error_not_found - task not found'));

        self::assertNull($api->getTask(1));
    }

    public function testGetTaskRethrowsTransientErrors(): void
    {
        $api = $this->apiThrowing(new QueryLimitExceededException('query limit exceeded'));

        $this->expectException(B24ApiException::class);
        $api->getTask(1);
    }

    public function testAddChecklistItemReadsScalarResultWrappedBySdk(): void
    {
        // Битрикс отдаёт "result": 475, SDK оборачивает скаляр в массив.
        $api = $this->apiReturning([475]);

        self::assertSame(475, $api->addChecklistItem(13, ['TITLE' => 'x']));
    }

    public function testRegisterBotReadsBotId(): void
    {
        $api = $this->apiReturning(['bot' => ['id' => 321], 'users' => []]);

        self::assertSame(321, $api->registerBot(['CODE' => 'bot']));
    }

    public function testFindTaskIdByCrmBindingReturnsNullOnEmptyList(): void
    {
        $api = $this->apiReturning(['tasks' => []]);

        self::assertNull($api->findTaskIdByCrmBinding('C_1', [5]));
    }

    public function testFindTaskIdByCrmBindingReturnsFirstId(): void
    {
        $api = $this->apiReturning(['tasks' => [['id' => '99'], ['id' => '98']]]);

        self::assertSame(99, $api->findTaskIdByCrmBinding('C_1', [5]));
    }

    public function testCrmEntityTitleIsBuiltFromNameWhenTitleIsMissing(): void
    {
        $api = $this->apiReturning(['ID' => 3, 'NAME' => 'Иван', 'LAST_NAME' => 'Петров']);

        $entity = $api->getCrmEntity('CONTACT', 3);

        self::assertNotNull($entity);
        self::assertSame('Петров Иван', $entity['TITLE']);
    }

    public function testCrmEntityIsNullForUnknownEntityType(): void
    {
        $api = $this->apiReturning(['ID' => 3]);

        self::assertNull($api->getCrmEntity('INVOICE', 3));
    }

    public function testGetChatFileDownloadUrlCallsBotMethodWithBotId(): void
    {
        // imbot.v2.File.download работает от имени бота (проверяются права владения
        // ботом), а не пользователя, чей OAuth-токен использует приложение, — в отличие
        // от disk.file.get, который на живом портале давал ACCESS_DENIED для файлов
        // открытых линий, где этот пользователь не является назначенным оператором.
        $calls = [];

        $responseData = $this->createMock(ResponseData::class);
        $responseData->method('getResult')->willReturn(['downloadUrl' => 'https://portal/rest/download.json?token=xyz']);

        $response = $this->createMock(Response::class);
        $response->method('getResponseData')->willReturn($responseData);

        $core = $this->createMock(CoreInterface::class);
        $core->method('call')
            ->willReturnCallback(function (string $method, array $params) use (&$calls, $response): Response {
                $calls[] = [$method, $params];

                return $response;
            });

        $api = new SdkB24Api($this->serviceBuilderWith($core), 2325, 'https://portal.example');
        $url = $api->getChatFileDownloadUrl(50021);

        self::assertSame([['imbot.v2.File.download', ['botId' => 2325, 'fileId' => 50021]]], $calls);
        self::assertSame('https://portal/rest/download.json?token=xyz', $url);
    }

    public function testUploadFileToAppStorageUsesAppStorageRootAndBase64(): void
    {
        $calls = [];
        $answers = [
            ['ID' => 1, 'ROOT_OBJECT_ID' => 8910],
            ['ID' => 9011, 'NAME' => 'a.pdf'],
        ];

        $core = $this->createMock(CoreInterface::class);
        $core->method('call')
            ->willReturnCallback(function (string $method, array $params) use (&$calls, &$answers): Response {
                $calls[] = [$method, $params];

                $responseData = $this->createMock(ResponseData::class);
                $responseData->method('getResult')->willReturn(array_shift($answers));
                $response = $this->createMock(Response::class);
                $response->method('getResponseData')->willReturn($responseData);

                return $response;
            });

        $api = new SdkB24Api($this->serviceBuilderWith($core), 456, 'https://portal.example');
        $stored = $api->uploadFileToAppStorage('a.pdf', 'BODY');

        self::assertSame(['id' => 9011, 'name' => 'a.pdf'], $stored);
        self::assertSame(['disk.storage.getforapp', []], $calls[0]);
        self::assertSame('disk.folder.uploadFile', $calls[1][0]);
        self::assertSame(8910, $calls[1][1]['id']);
        self::assertSame(['a.pdf', base64_encode('BODY')], $calls[1][1]['fileContent']);
    }

    public function testAttachFileToTaskCallsLegacyMethodAndBuildsAttachmentUrl(): void
    {
        // tasks.task.file.attach (REST 3.0) отсутствует на части порталов ("api method not
        // found"), поэтому используется tasks.task.files.attach: один fileId за вызов.
        $calls = [];

        $responseData = $this->createMock(ResponseData::class);
        $responseData->method('getResult')->willReturn(['attachmentId' => 1079]);

        $response = $this->createMock(Response::class);
        $response->method('getResponseData')->willReturn($responseData);

        $core = $this->createMock(CoreInterface::class);
        $core->method('call')
            ->willReturnCallback(function (string $method, array $params) use (&$calls, $response): Response {
                $calls[] = [$method, $params];

                return $response;
            });

        $api = new SdkB24Api($this->serviceBuilderWith($core), 456, 'https://portal.example');
        $url = $api->attachFileToTask(13, 101);

        self::assertSame([['tasks.task.files.attach', ['taskId' => 13, 'fileId' => 101]]], $calls);
        self::assertSame('https://portal.example/bitrix/tools/disk/uf.php?attachedId=1079&action=download&ncc=1', $url);
    }

    public function testAttachFileToTaskFailsWithoutAttachmentId(): void
    {
        $api = $this->apiReturning([]);

        $this->expectException(B24ApiException::class);
        $api->attachFileToTask(13, 101);
    }

    private function apiReturning(array $result): SdkB24Api
    {
        $responseData = $this->createMock(ResponseData::class);
        $responseData->method('getResult')->willReturn($result);

        $response = $this->createMock(Response::class);
        $response->method('getResponseData')->willReturn($responseData);

        $core = $this->createMock(CoreInterface::class);
        $core->method('call')->willReturn($response);

        return new SdkB24Api($this->serviceBuilderWith($core), 456, 'https://portal.example');
    }

    private function apiThrowing(Throwable $exception): SdkB24Api
    {
        $core = $this->createMock(CoreInterface::class);
        $core->method('call')->willThrowException($exception);

        return new SdkB24Api($this->serviceBuilderWith($core), 456, 'https://portal.example');
    }

    private function serviceBuilderWith(CoreInterface $core): ServiceBuilder
    {
        $serviceBuilder = $this->createMock(ServiceBuilder::class);
        $serviceBuilder->core = $core;

        return $serviceBuilder;
    }
}
