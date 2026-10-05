<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Bitrix;

use B24DocsBot\Bitrix\B24ApiException;
use B24DocsBot\Bitrix\SdkB24Api;
use Bitrix24\SDK\Core\Contracts\CoreInterface;
use Bitrix24\SDK\Core\Exceptions\QueryLimitExceededException;
use Bitrix24\SDK\Core\Response\DTO\ResponseData;
use Bitrix24\SDK\Core\Response\Response;
use Bitrix24\SDK\Services\ServiceBuilder;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Проверяется только чистая логика нормализации ответов и параметров: сетевых вызовов нет,
 * `core` подменён заглушкой.
 */
final class SdkB24ApiTest extends TestCase
{
    public function testRegisterBotReadsBotId(): void
    {
        $api = $this->apiReturning(['bot' => ['id' => 321], 'users' => []]);

        self::assertSame(321, $api->registerBot(['CODE' => 'bot']));
    }

    public function testGetChatFileDownloadUrlCallsBotMethodWithBotId(): void
    {
        // imbot.v2.File.download работает от имени бота (проверяются права владения
        // ботом), а не пользователя, чей OAuth-токен использует приложение, — в отличие
        // от disk.file.get, который на живом портале давал ACCESS_DENIED для файлов
        // открытых линий, где этот пользователь не является назначенным оператором.
        $calls = [];
        $api = $this->apiRecording(['downloadUrl' => 'https://portal/rest/download.json?token=xyz'], $calls, 2325);

        $url = $api->getChatFileDownloadUrl(50021);

        self::assertSame([['imbot.v2.File.download', ['botId' => 2325, 'fileId' => 50021]]], $calls);
        self::assertSame('https://portal/rest/download.json?token=xyz', $url);
    }

    public function testAddDealTimelineCommentSendsFileAsBase64AndReadsScalarId(): void
    {
        $calls = [];
        $api = $this->apiRecording([999], $calls);

        $id = $api->addDealTimelineComment(5547, 'Документ', 'a.pdf', 'BODY');

        self::assertSame(999, $id);
        self::assertSame([[
            'crm.timeline.comment.add',
            ['fields' => [
                'ENTITY_ID' => 5547,
                'ENTITY_TYPE' => 'deal',
                'COMMENT' => 'Документ',
                'FILES' => [['a.pdf', base64_encode('BODY')]],
            ]],
        ]], $calls);
    }

    public function testAddDealTimelineCommentFailsWithoutId(): void
    {
        $api = $this->apiReturning([]);

        $this->expectException(B24ApiException::class);
        $api->addDealTimelineComment(5547, 'Документ', 'a.pdf', 'BODY');
    }

    public function testPinAndUnpinUseDealOwnerType(): void
    {
        $calls = [];
        $api = $this->apiRecording([null], $calls);

        $api->pinTimelineItem(999, 5547);
        $api->unpinTimelineItem(998, 5547);

        self::assertSame([
            ['crm.timeline.item.pin', ['id' => 999, 'ownerTypeId' => 2, 'ownerId' => 5547]],
            ['crm.timeline.item.unpin', ['id' => 998, 'ownerTypeId' => 2, 'ownerId' => 5547]],
        ], $calls);
    }

    public function testTransientErrorsAreMarkedTransient(): void
    {
        $api = $this->apiThrowing(new QueryLimitExceededException('query limit exceeded'));

        try {
            $api->pinTimelineItem(1, 2);
            self::fail('ожидалось исключение');
        } catch (B24ApiException $exception) {
            self::assertTrue($exception->isTransient());
        }
    }

    private function apiRecording(array $result, array &$calls, int $botId = 456): SdkB24Api
    {
        $responseData = $this->createMock(ResponseData::class);
        $responseData->method('getResult')->willReturn($result);

        $response = $this->createMock(Response::class);
        $response->method('getResponseData')->willReturn($responseData);

        $core = $this->createMock(CoreInterface::class);
        $core->method('call')
            ->willReturnCallback(function (string $method, array $params) use (&$calls, $response): Response {
                $calls[] = [$method, $params];

                return $response;
            });

        return new SdkB24Api($this->serviceBuilderWith($core), $botId);
    }

    private function apiReturning(array $result): SdkB24Api
    {
        $responseData = $this->createMock(ResponseData::class);
        $responseData->method('getResult')->willReturn($result);

        $response = $this->createMock(Response::class);
        $response->method('getResponseData')->willReturn($responseData);

        $core = $this->createMock(CoreInterface::class);
        $core->method('call')->willReturn($response);

        return new SdkB24Api($this->serviceBuilderWith($core), 456);
    }

    private function apiThrowing(Throwable $exception): SdkB24Api
    {
        $core = $this->createMock(CoreInterface::class);
        $core->method('call')->willThrowException($exception);

        return new SdkB24Api($this->serviceBuilderWith($core), 456);
    }

    private function serviceBuilderWith(CoreInterface $core): ServiceBuilder
    {
        $serviceBuilder = $this->createMock(ServiceBuilder::class);
        $serviceBuilder->core = $core;

        return $serviceBuilder;
    }
}
