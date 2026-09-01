<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Logging;

use B24DocsBot\Logging\RedactingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class RedactingProcessorTest extends TestCase
{
    private function record(array $context): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'test', Level::Info, 'сообщение', $context);
    }

    public function testRedactsSecretKeysAtTopLevel(): void
    {
        $processed = (new RedactingProcessor())($this->record([
            'access_token' => 'secret-value',
            'task_id' => 555,
        ]));

        self::assertSame('***', $processed->context['access_token']);
        self::assertSame(555, $processed->context['task_id']);
    }

    public function testRedactsSecretKeysInNestedArrays(): void
    {
        $processed = (new RedactingProcessor())($this->record([
            'auth' => ['application_token' => 'secret-value', 'domain' => 'portal.bitrix24.ru'],
        ]));

        self::assertSame('***', $processed->context['auth']['application_token']);
        self::assertSame('portal.bitrix24.ru', $processed->context['auth']['domain']);
    }

    public function testRedactionIsCaseInsensitive(): void
    {
        $processed = (new RedactingProcessor())($this->record(['REFRESH_TOKEN' => 'secret-value']));

        self::assertSame('***', $processed->context['REFRESH_TOKEN']);
    }

    public function testRedactsBotToken(): void
    {
        $processed = (new RedactingProcessor())($this->record(['bot_token' => 'secret-value']));

        self::assertSame('***', $processed->context['bot_token']);
    }
}
