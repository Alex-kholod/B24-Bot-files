<?php

declare(strict_types=1);

namespace B24DocsBot\Storage;

use PDO;

final class TokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function save(array $portal): void
    {
        $sql = <<<'SQL'
            INSERT INTO auth_tokens (
                member_id, domain, client_endpoint, access_token, refresh_token,
                expires_at, application_token, installed_by_user_id, updated_at
            ) VALUES (
                :member_id, :domain, :client_endpoint, :access_token, :refresh_token,
                :expires_at, :application_token, :installed_by_user_id, :updated_at
            )
            ON CONFLICT(member_id) DO UPDATE SET
                domain = excluded.domain,
                client_endpoint = excluded.client_endpoint,
                access_token = excluded.access_token,
                refresh_token = excluded.refresh_token,
                expires_at = excluded.expires_at,
                application_token = excluded.application_token,
                installed_by_user_id = excluded.installed_by_user_id,
                updated_at = excluded.updated_at
            SQL;

        $this->pdo->prepare($sql)->execute([
            'member_id' => (string) $portal['member_id'],
            'domain' => (string) $portal['domain'],
            'client_endpoint' => (string) $portal['client_endpoint'],
            'access_token' => (string) $portal['access_token'],
            'refresh_token' => (string) $portal['refresh_token'],
            'expires_at' => (int) ($portal['expires_at'] ?? 0),
            'application_token' => (string) ($portal['application_token'] ?? ''),
            'installed_by_user_id' => (int) ($portal['installed_by_user_id'] ?? 0),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function find(): ?array
    {
        $row = $this->pdo
            ->query('SELECT * FROM auth_tokens ORDER BY updated_at DESC LIMIT 1')
            ->fetch();

        if ($row === false) {
            return null;
        }

        $row['expires_at'] = (int) $row['expires_at'];
        $row['bot_id'] = (int) $row['bot_id'];
        $row['installed_by_user_id'] = (int) $row['installed_by_user_id'];

        return $row;
    }

    public function updateTokens(string $memberId, string $accessToken, string $refreshToken, int $expiresAt): void
    {
        $sql = 'UPDATE auth_tokens SET access_token = ?, refresh_token = ?, expires_at = ?, updated_at = ? WHERE member_id = ?';

        $this->pdo->prepare($sql)->execute([
            $accessToken,
            $refreshToken,
            $expiresAt,
            gmdate('Y-m-d H:i:s'),
            $memberId,
        ]);
    }

    public function saveBot(string $memberId, int $botId, string $botCode): void
    {
        $sql = 'UPDATE auth_tokens SET bot_id = ?, bot_code = ?, updated_at = ? WHERE member_id = ?';

        $this->pdo->prepare($sql)->execute([$botId, $botCode, gmdate('Y-m-d H:i:s'), $memberId]);
    }

    public function applicationToken(): string
    {
        return (string) ($this->find()['application_token'] ?? '');
    }
}
