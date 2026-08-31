<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Bot;

use B24DocsBot\Bot\EventRouter;
use PHPUnit\Framework\TestCase;

final class EventRouterTest extends TestCase
{
    private const APP_TOKEN = 'app-token-value';

    private function request(array $overrides = []): array
    {
        $request = [
            'event' => 'ONIMBOTV2MESSAGEADD',
            'data' => [
                'bot' => ['id' => '456', 'code' => 'ol_docs_bot'],
                'message' => [
                    'id' => '789',
                    'chatId' => '5',
                    'authorId' => '1269',
                    'text' => 'Вот документы',
                    'params' => ['FILE_ID' => ['77', '78']],
                ],
                'chat' => ['id' => '5', 'dialogId' => 'chat5', 'entityType' => 'LINES'],
                'user' => ['id' => '1269', 'bot' => '0'],
            ],
            'auth' => ['application_token' => self::APP_TOKEN],
        ];

        return array_replace_recursive($request, $overrides);
    }

    private function router(): EventRouter
    {
        return new EventRouter(self::APP_TOKEN);
    }

    public function testVerifyAcceptsMatchingToken(): void
    {
        self::assertTrue($this->router()->verify($this->request()));
    }

    public function testVerifyRejectsWrongToken(): void
    {
        self::assertFalse($this->router()->verify($this->request(['auth' => ['application_token' => 'other']])));
    }

    public function testVerifyRejectsMissingToken(): void
    {
        $request = $this->request();
        unset($request['auth']);

        self::assertFalse($this->router()->verify($request));
    }

    public function testVerifyIgnoresBotLevelToken(): void
    {
        $request = $this->request();
        $request['data']['bot']['auth'] = ['application_token' => self::APP_TOKEN];
        $request['auth']['application_token'] = 'other';

        self::assertFalse($this->router()->verify($request));
    }

    public function testParseCastsStringScalarsToIntegers(): void
    {
        $event = $this->router()->parse($this->request());

        self::assertNotNull($event);
        self::assertSame(789, $event->messageId);
        self::assertSame(5, $event->chatId);
        self::assertSame(1269, $event->authorId);
        self::assertSame(456, $event->botId);
        self::assertSame([77, 78], $event->fileIds);
    }

    public function testParseDetectsOpenLineChat(): void
    {
        self::assertTrue($this->router()->parse($this->request())->isOpenLine());
        self::assertFalse(
            $this->router()->parse($this->request(['data' => ['chat' => ['entityType' => '']]]))->isOpenLine()
        );
    }

    public function testParseMarksBotAuthors(): void
    {
        self::assertFalse($this->router()->parse($this->request())->authorIsBot);

        $fromBot = $this->request(['data' => ['user' => ['bot' => '1']]]);
        self::assertTrue($this->router()->parse($fromBot)->authorIsBot);
    }

    public function testParseTreatsAuthorEqualToBotIdAsBot(): void
    {
        $fromSelf = $this->request(['data' => ['message' => ['authorId' => '456']]]);

        self::assertTrue($this->router()->parse($fromSelf)->authorIsBot);
    }

    public function testParseAcceptsScalarFileId(): void
    {
        $single = $this->request();
        $single['data']['message']['params']['FILE_ID'] = '77';

        self::assertSame([77], $this->router()->parse($single)->fileIds);
    }

    public function testParseReturnsEmptyFileListWhenNoFiles(): void
    {
        $noFiles = $this->request();
        unset($noFiles['data']['message']['params']);

        $event = $this->router()->parse($noFiles);

        self::assertSame([], $event->fileIds);
        self::assertFalse($event->hasFiles());
    }

    public function testParseIgnoresZeroAndNonNumericFileIds(): void
    {
        $noisy = $this->request();
        $noisy['data']['message']['params']['FILE_ID'] = ['77', '0', '', 'abc'];

        self::assertSame([77], $this->router()->parse($noisy)->fileIds);
    }

    public function testParseReturnsNullForOtherEvents(): void
    {
        self::assertNull($this->router()->parse($this->request(['event' => 'ONIMBOTV2JOINCHAT'])));
    }

    public function testParseReturnsNullWhenMessageIdMissing(): void
    {
        $broken = $this->request();
        unset($broken['data']['message']['id']);

        self::assertNull($this->router()->parse($broken));
    }
}
