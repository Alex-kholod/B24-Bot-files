<?php

declare(strict_types=1);

namespace B24DocsBot\Storage;

use DateTimeImmutable;
use PDO;

final class PendingFileRepository
{
    private const STATUS_NEW = 'new';
    private const STATUS_DONE = 'done';
    private const STATUS_FAILED = 'failed';
    private const MAX_BACKOFF_MINUTES = 30;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function enqueue(
        int $messageId,
        int $chatId,
        int $chatFileId,
        string $fileName,
        DateTimeImmutable $now
    ): int {
        $timestamp = $now->format('Y-m-d H:i:s');

        $sql = <<<'SQL'
            INSERT INTO pending_files (
                message_id, chat_id, chat_file_id, file_name, status, attempts,
                next_attempt_at, created_at, updated_at
            ) VALUES (:message_id, :chat_id, :chat_file_id, :file_name, 'new', 0, :now, :now, :now)
            ON CONFLICT(message_id, chat_file_id) DO NOTHING
            SQL;

        $this->pdo->prepare($sql)->execute([
            'message_id' => $messageId,
            'chat_id' => $chatId,
            'chat_file_id' => $chatFileId,
            'file_name' => $fileName,
            'now' => $timestamp,
        ]);

        $statement = $this->pdo->prepare(
            'SELECT id FROM pending_files WHERE message_id = ? AND chat_file_id = ?'
        );
        $statement->execute([$messageId, $chatFileId]);

        return (int) $statement->fetchColumn();
    }

    public function newForMessage(int $messageId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM pending_files WHERE message_id = ? AND status = 'new' ORDER BY id"
        );
        $statement->execute([$messageId]);

        return array_map($this->castRow(...), $statement->fetchAll());
    }

    public function due(DateTimeImmutable $now, int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM pending_files WHERE status = 'new' AND next_attempt_at <= ? ORDER BY id LIMIT ?"
        );
        $statement->execute([$now->format('Y-m-d H:i:s'), $limit]);

        return array_map($this->castRow(...), $statement->fetchAll());
    }

    public function markDone(int $id, int $taskId, DateTimeImmutable $now): void
    {
        $sql = 'UPDATE pending_files SET status = ?, task_id = ?, last_error = ?, updated_at = ? WHERE id = ?';

        $this->pdo->prepare($sql)->execute([
            self::STATUS_DONE,
            $taskId,
            '',
            $now->format('Y-m-d H:i:s'),
            $id,
        ]);
    }

    public function markFailure(int $id, string $error, int $maxAttempts, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare('SELECT attempts FROM pending_files WHERE id = ?');
        $statement->execute([$id]);
        $attempts = (int) $statement->fetchColumn() + 1;

        $status = $attempts >= $maxAttempts ? self::STATUS_FAILED : self::STATUS_NEW;
        $delayMinutes = min(2 ** ($attempts - 1), self::MAX_BACKOFF_MINUTES);
        $nextAttempt = $now->modify("+{$delayMinutes} minutes");

        $sql = 'UPDATE pending_files SET attempts = ?, status = ?, last_error = ?, next_attempt_at = ?, updated_at = ? WHERE id = ?';

        $this->pdo->prepare($sql)->execute([
            $attempts,
            $status,
            mb_substr($error, 0, 500),
            $nextAttempt->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
            $id,
        ]);
    }

    public function requeueFailed(DateTimeImmutable $now): int
    {
        $sql = "UPDATE pending_files SET status = 'new', attempts = 0, next_attempt_at = ?, updated_at = ? WHERE status = 'failed'";

        $statement = $this->pdo->prepare($sql);
        $statement->execute([$now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')]);

        return $statement->rowCount();
    }

    private function castRow(array $row): array
    {
        foreach (['id', 'message_id', 'chat_id', 'chat_file_id', 'task_id', 'attempts'] as $key) {
            $row[$key] = (int) $row[$key];
        }

        return $row;
    }
}
