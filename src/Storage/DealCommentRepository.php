<?php

declare(strict_types=1);

namespace B24DocsBot\Storage;

use PDO;

final class DealCommentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(int $dealId): ?int
    {
        $statement = $this->pdo->prepare('SELECT comment_id FROM deal_comments WHERE deal_id = ?');
        $statement->execute([$dealId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function save(int $dealId, int $commentId): void
    {
        $sql = 'INSERT INTO deal_comments (deal_id, comment_id) VALUES (?, ?)'
            . ' ON CONFLICT(deal_id) DO UPDATE SET comment_id = excluded.comment_id';

        $this->pdo->prepare($sql)->execute([$dealId, $commentId]);
    }

    public function forget(int $dealId): void
    {
        $this->pdo->prepare('DELETE FROM deal_comments WHERE deal_id = ?')->execute([$dealId]);
    }
}
