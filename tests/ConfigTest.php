<?php

declare(strict_types=1);

namespace B24DocsBot\Tests;

use B24DocsBot\Config;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private function validValues(): array
    {
        return [
            'client_id' => 'local.abc',
            'client_secret' => 'secret',
            'scope' => 'imbot,imopenlines,im,task,crm,disk,user',
            'bot_code' => 'docs_bot',
            'bot_name' => 'Документы',
            'bot_token' => 'token',
            'handler_url' => 'https://example.org/handler.php',
            'default_responsible_id' => 1,
            'db_path' => '/tmp/bot.sqlite',
            'log_path' => '/tmp/log',
        ];
    }

    public function testReturnsTypedValues(): void
    {
        $config = Config::fromArray($this->validValues());

        self::assertSame('local.abc', $config->string('client_id'));
        self::assertSame(1, $config->int('default_responsible_id'));
    }

    public function testAppliesDefaults(): void
    {
        $config = Config::fromArray($this->validValues());

        self::assertSame('Документы от клиента', $config->string('checklist_title'));
        self::assertSame(10, $config->int('max_attempts'));
        self::assertSame(0, $config->int('task_group_id'));
    }

    public function testDefaultsCanBeOverridden(): void
    {
        $config = Config::fromArray($this->validValues() + ['max_attempts' => 3]);

        self::assertSame(3, $config->int('max_attempts'));
    }

    public function testMissingRequiredKeysListedInMessage(): void
    {
        $values = $this->validValues();
        unset($values['client_secret'], $values['bot_token']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('client_secret, bot_token');

        Config::fromArray($values);
    }

    public function testEmptyStringCountsAsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Config::fromArray(['client_id' => ''] + $this->validValues());
    }
}
