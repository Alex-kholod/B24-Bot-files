<?php

declare(strict_types=1);

namespace B24DocsBot\Service;

use B24DocsBot\Bitrix\B24Api;
use B24DocsBot\Storage\TaskLinkRepository;

final class TaskResolver
{
    /** Завершена и отклонена. Сверить числовые коды через tasks.task.field.list. */
    public const CLOSED_STATUSES = [5, 7];

    public function __construct(
        private readonly B24Api $api,
        private readonly TaskLinkRepository $links,
        private readonly int $defaultResponsibleId,
        private readonly int $taskGroupId,
        private readonly int $createdById,
    ) {
    }

    public function resolve(ClientRef $client): int
    {
        $clientKey = $client->clientKey();
        $cached = $this->links->find($clientKey);

        if ($cached !== null && $this->isUsable($this->api->getTask($cached['task_id']))) {
            $this->links->touch($clientKey);

            return $cached['task_id'];
        }

        $found = $this->api->findTaskIdByCrmBinding($client->crmBinding(), self::CLOSED_STATUSES);

        if ($found !== null && $this->isUsable($this->api->getTask($found))) {
            $this->links->save($clientKey, $client->crmEntityType, $client->crmEntityId, $found);

            return $found;
        }

        $taskId = $this->api->addTask($this->buildTaskFields($client));
        $this->links->save($clientKey, $client->crmEntityType, $client->crmEntityId, $taskId);

        return $taskId;
    }

    private function isUsable(?array $task): bool
    {
        if ($task === null) {
            return false;
        }

        if ((bool) ($task['isDeleted'] ?? false)) {
            return false;
        }

        return !in_array((int) ($task['status'] ?? 0), self::CLOSED_STATUSES, true);
    }

    private function buildTaskFields(ClientRef $client): array
    {
        $entity = $this->api->getCrmEntity($client->crmEntityType, $client->crmEntityId);
        $responsibleId = (int) ($entity['ASSIGNED_BY_ID'] ?? 0);

        $fields = [
            'TITLE' => 'Документы клиента: ' . $client->title,
            'RESPONSIBLE_ID' => $responsibleId > 0 ? $responsibleId : $this->defaultResponsibleId,
            'CREATED_BY' => $this->createdById,
            'UF_CRM_TASK' => [$client->crmBinding()],
            'DESCRIPTION' => 'Задача создана автоматически для документов из открытой линии.',
        ];

        if ($this->taskGroupId > 0) {
            $fields['GROUP_ID'] = $this->taskGroupId;
        }

        return $fields;
    }
}
