<?php

declare(strict_types=1);

namespace B24DocsBot\Storage;

use PDO;

final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $sql = 'INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value';

        $this->pdo->prepare($sql)->execute([$key, $value]);
    }

    public function delete(string $key): void
    {
        $this->pdo->prepare('DELETE FROM settings WHERE key = ?')->execute([$key]);
    }
}
