<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Storage;

use B24DocsBot\Storage\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    public function testMigrateCreatesAllTables(): void
    {
        $db = new Database(':memory:');
        $applied = $db->migrate();

        self::assertGreaterThan(0, $applied);

        $tables = $db->pdo()
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);

        foreach (['auth_tokens', 'pending_files', 'processed_messages', 'settings', 'task_links'] as $table) {
            self::assertContains($table, $tables);
        }
    }

    public function testMigrateIsIdempotent(): void
    {
        $db = new Database(':memory:');
        $db->migrate();

        self::assertSame(0, $db->migrate());
    }

    public function testPendingFilesHasUniqueIndexOnMessageAndFile(): void
    {
        $db = new Database(':memory:');
        $db->migrate();

        $insert = 'INSERT INTO pending_files (message_id, chat_id, chat_file_id, file_name, status, attempts, next_attempt_at, created_at, updated_at)'
            . " VALUES (10, 5, 77, 'a.pdf', 'new', 0, '2026-08-31 10:00:00', '2026-08-31 10:00:00', '2026-08-31 10:00:00')";

        $db->pdo()->exec($insert);

        $this->expectException(\PDOException::class);
        $db->pdo()->exec($insert);
    }
}
