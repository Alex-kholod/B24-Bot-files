<?php

declare(strict_types=1);

namespace B24DocsBot\Storage;

use PDO;

final class TaskLinkRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(string $clientKey): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM task_links WHERE client_key = ?');
        $statement->execute([$clientKey]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $row['crm_entity_id'] = (int) $row['crm_entity_id'];
        $row['task_id'] = (int) $row['task_id'];
        $row['checklist_id'] = (int) $row['checklist_id'];

        return $row;
    }

    public function save(string $clientKey, string $crmEntityType, int $crmEntityId, int $taskId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $sql = <<<'SQL'
            INSERT INTO task_links (
                client_key, crm_entity_type, crm_entity_id, task_id, checklist_id,
                created_at, updated_at, last_used_at
            ) VALUES (:client_key, :type, :entity_id, :task_id, 0, :now, :now, :now)
            ON CONFLICT(client_key) DO UPDATE SET
                crm_entity_type = excluded.crm_entity_type,
                crm_entity_id = excluded.crm_entity_id,
                task_id = excluded.task_id,
                checklist_id = 0,
                updated_at = excluded.updated_at,
                last_used_at = excluded.last_used_at
            SQL;

        $this->pdo->prepare($sql)->execute([
            'client_key' => $clientKey,
            'type' => $crmEntityType,
            'entity_id' => $crmEntityId,
            'task_id' => $taskId,
            'now' => $now,
        ]);
    }

    public function setChecklistId(string $clientKey, int $checklistId): void
    {
        $sql = 'UPDATE task_links SET checklist_id = ?, updated_at = ? WHERE client_key = ?';

        $this->pdo->prepare($sql)->execute([$checklistId, gmdate('Y-m-d H:i:s'), $clientKey]);
    }

    public function touch(string $clientKey): void
    {
        $this->pdo
            ->prepare('UPDATE task_links SET last_used_at = ? WHERE client_key = ?')
            ->execute([gmdate('Y-m-d H:i:s'), $clientKey]);
    }
}
