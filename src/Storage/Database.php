<?php

declare(strict_types=1);

namespace B24DocsBot\Storage;

use PDO;
use RuntimeException;

final class Database
{
    private readonly PDO $pdo;

    public function __construct(string $path)
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
                throw new RuntimeException("Не удалось создать каталог базы данных: {$dir}");
            }
        }

        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function migrate(): int
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)'
        );

        $applied = $this->pdo
            ->query('SELECT name FROM schema_migrations')
            ->fetchAll(PDO::FETCH_COLUMN);

        $count = 0;
        $insert = $this->pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)');

        foreach (Migrations::all() as $name => $sql) {
            if (in_array($name, $applied, true)) {
                continue;
            }

            $this->pdo->exec($sql);
            $insert->execute([$name, gmdate('Y-m-d H:i:s')]);
            ++$count;
        }

        return $count;
    }
}
