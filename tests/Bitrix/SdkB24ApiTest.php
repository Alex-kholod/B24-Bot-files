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

    public function testSaveChatFileToDiskReadsNestedFileId(): void
    {
        $api = $this->apiReturning(['folder' => ['id' => 5], 'file' => ['id' => 9043, 'name' => 'a.pdf']]);

        self::assertSame(9043, $api->saveChatFileToDisk(11, 5155));
    }

    public function testSaveChatFileToDiskFailsOnUnexpectedAnswer(): void
    {
        $api = $this->apiReturning(['folder' => ['id' => 5]]);

        $this->expectException(B24ApiException::class);
        $api->saveChatFileToDisk(11, 5155);
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

    public function testAttachFilesToTaskCallsLegacyMethodOncePerFileWithSingularFileId(): void
    {
        // tasks.task.file.attach (REST 3.0, множественный fileIds) отсутствует на части
        // порталов ("api method not found") — REST 3.0 для задач там не включён. Используем
        // tasks.task.files.attach: он принимает ровно один fileId за вызов, не массив.
        $calls = [];

        $responseData = $this->createMock(ResponseData::class);
        $responseData->method('getResult')->willReturn(['attachmentId' => 1]);

        $response = $this->createMock(Response::class);
        $response->method('getResponseData')->willReturn($responseData);

        $core = $this->createMock(CoreInterface::class);
        $core->method('call')
            ->willReturnCallback(function (string $method, array $params) use (&$calls, $response): Response {
                $calls[] = [$method, $params];

                return $response;
            });

        $api = new SdkB24Api($this->serviceBuilderWith($core));
        $api->attachFilesToTask(13, [101, 102]);

        self::assertSame([
            ['tasks.task.files.attach', ['taskId' => 13, 'fileId' => 101]],
            ['tasks.task.files.attach', ['taskId' => 13, 'fileId' => 102]],
        ], $calls);
    }

    private function apiReturning(array $result): SdkB24Api
    {
        $responseData = $this->createMock(ResponseData::class);
        $responseData->method('getResult')->willReturn($result);

        $response = $this->createMock(Response::class);
        $response->method('getResponseData')->willReturn($responseData);

        $core = $this->createMock(CoreInterface::class);
        $core->method('call')->willReturn($response);

        return new SdkB24Api($this->serviceBuilderWith($core));
    }

    private function apiThrowing(Throwable $exception): SdkB24Api
    {
        $core = $this->createMock(CoreInterface::class);
        $core->method('call')->willThrowException($exception);

        return new SdkB24Api($this->serviceBuilderWith($core));
    }

    private function serviceBuilderWith(CoreInterface $core): ServiceBuilder
    {
        $serviceBuilder = $this->createMock(ServiceBuilder::class);
        $serviceBuilder->core = $core;

        return $serviceBuilder;
    }
}
