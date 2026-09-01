<?php

declare(strict_types=1);

namespace B24DocsBot\Service;

use B24DocsBot\Bitrix\B24Api;

final class ClientResolver
{
    public function __construct(private readonly B24Api $api)
    {
    }

    public function resolve(int $chatId): ?ClientRef
    {
        $dialog = $this->api->getOpenLineDialog($chatId);

        $type = strtoupper($this->pick($dialog, ['crm_entity_type', 'CRM_ENTITY_TYPE', 'entityType']));
        $entityId = (int) $this->pick($dialog, ['crm_entity_id', 'CRM_ENTITY_ID', 'entityId']);

        if ($entityId <= 0 || !isset(ClientRef::BINDING_PREFIXES[$type])) {
            return null;
        }

        $entity = $this->api->getCrmEntity($type, $entityId);
        $title = trim((string) ($entity['TITLE'] ?? ''));

        if ($title === '') {
            $title = "{$type} {$entityId}";
        }

        return new ClientRef($type, $entityId, $title);
    }

    /**
     * @param string[] $keys
     */
    private function pick(array $dialog, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($dialog[$key]) && $dialog[$key] !== '') {
                return (string) $dialog[$key];
            }
        }

        return '';
    }
}
