<?php

declare(strict_types=1);

namespace B24DocsBot\Tests;

use B24DocsBot\Application;
use B24DocsBot\Config;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    private function application(): Application
    {
        return new Application(Config::fromArray([
            'client_id' => 'local.abc',
            'client_secret' => 'secret',
            'scope' => 'imbot,imopenlines,im,task,tasks,crm,disk,user',
            'bot_code' => 'ol_docs_bot',
            'bot_name' => 'Документы',
            'bot_token' => 'token',
            'handler_url' => 'https://example.org/handler.php',
            'default_responsible_id' => 1,
            'db_path' => ':memory:',
            'log_path' => sys_get_temp_dir() . '/b24-docs-bot-test',
        ]));
    }

    public function testMigratesDatabaseOnBoot(): void
    {
        $app = $this->application();

        $tables = $app->database()->pdo()
            ->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertContains('pending_files', $tables);
    }

    public function testRepositoriesAreSharedInstances(): void
    {
        $app = $this->application();

        self::assertSame($app->tokens(), $app->tokens());
        self::assertSame($app->pending(), $app->pending());
    }

    public function testEventRouterUsesStoredApplicationToken(): void
    {
        $app = $this->application();
        $app->tokens()->save([
            'member_id' => 'member-1',
            'domain' => 'portal.bitrix24.ru',
            'client_endpoint' => 'https://portal.bitrix24.ru/rest/',
            'access_token' => 'a',
            'refresh_token' => 'r',
            'expires_at' => 0,
            'application_token' => 'app-token',
            'installed_by_user_id' => 3,
        ]);

        self::assertTrue($app->eventRouter()->verify(['auth' => ['application_token' => 'app-token']]));
        self::assertFalse($app->eventRouter()->verify(['auth' => ['application_token' => 'wrong']]));
    }
}
