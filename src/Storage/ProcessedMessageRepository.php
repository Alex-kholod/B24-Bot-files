<?php

declare(strict_types=1);

namespace B24DocsBot\Storage;

use PDO;

final class ProcessedMessageRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isProcessed(int $messageId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM processed_messages WHERE message_id = ?');
        $statement->execute([$messageId]);

        return $statement->fetchColumn() !== false;
    }

    public function markProcessed(int $messageId, int $chatId): void
    {
        $sql = 'INSERT INTO processed_messages (message_id, chat_id, processed_at) VALUES (?, ?, ?)'
            . ' ON CONFLICT(message_id) DO NOTHING';

        $this->pdo->prepare($sql)->execute([$messageId, $chatId, gmdate('Y-m-d H:i:s')]);
    }
}
