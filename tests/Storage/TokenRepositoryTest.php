<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Storage;

use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\TokenRepository;
use PHPUnit\Framework\TestCase;

final class TokenRepositoryTest extends TestCase
{
    private Database $db;
    private TokenRepository $repository;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->db->migrate();
        $this->repository = new TokenRepository($this->db->pdo());
    }

    private function portal(array $overrides = []): array
    {
        return $overrides + [
            'member_id' => 'member-1',
            'domain' => 'portal.bitrix24.ru',
            'client_endpoint' => 'https://portal.bitrix24.ru/rest/',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_at' => 1000,
            'application_token' => 'app-token',
            'installed_by_user_id' => 7,
        ];
    }

    public function testFindReturnsNullWhenNotInstalled(): void
    {
        self::assertNull($this->repository->find());
    }

    public function testSaveThenFind(): void
    {
        $this->repository->save($this->portal());
        $found = $this->repository->find();

        self::assertNotNull($found);
        self::assertSame('member-1', $found['member_id']);
        self::assertSame('access-1', $found['access_token']);
        self::assertSame(7, $found['installed_by_user_id']);
    }

    public function testSaveTwiceOverwritesSamePortal(): void
    {
        $this->repository->save($this->portal());
        $this->repository->save($this->portal(['access_token' => 'access-2']));

        $rows = $this->db->pdo()->query('SELECT COUNT(*) FROM auth_tokens')->fetchColumn();

        self::assertSame(1, (int) $rows);
        self::assertSame('access-2', $this->repository->find()['access_token']);
    }

    public function testUpdateTokensKeepsOtherFields(): void
    {
        $this->repository->save($this->portal());
        $this->repository->updateTokens('member-1', 'access-9', 'refresh-9', 2000);

        $found = $this->repository->find();

        self::assertSame('access-9', $found['access_token']);
        self::assertSame('refresh-9', $found['refresh_token']);
        self::assertSame(2000, $found['expires_at']);
        self::assertSame('app-token', $found['application_token']);
    }

    public function testSaveBotStoresIdAndCode(): void
    {
        $this->repository->save($this->portal());
        $this->repository->saveBot('member-1', 456, 'ol_docs_bot');

        $found = $this->repository->find();

        self::assertSame(456, $found['bot_id']);
        self::assertSame('ol_docs_bot', $found['bot_code']);
    }

    public function testApplicationTokenReturnsEmptyStringWhenNotInstalled(): void
    {
        self::assertSame('', $this->repository->applicationToken());
    }
}
