<?php

declare(strict_types=1);

namespace B24DocsBot\Storage;

use DateTimeImmutable;
use PDO;

final class PinnedCommentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return int[] id закреплённых ботом комментариев сделки, от самого старого к новому */
    public function forDeal(int $dealId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT comment_id FROM pinned_comments WHERE deal_id = ? ORDER BY created_at, comment_id'
        );
        $statement->execute([$dealId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function add(int $dealId, int $commentId, DateTimeImmutable $now): void
    {
        $sql = 'INSERT OR REPLACE INTO pinned_comments (comment_id, deal_id, created_at) VALUES (?, ?, ?)';

        $this->pdo->prepare($sql)->execute([$commentId, $dealId, $now->format('Y-m-d H:i:s')]);
    }

    public function remove(int $commentId): void
    {
        $this->pdo->prepare('DELETE FROM pinned_comments WHERE comment_id = ?')->execute([$commentId]);
    }
}
