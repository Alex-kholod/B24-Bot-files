<?php

declare(strict_types=1);

namespace B24DocsBot\Service;

use B24DocsBot\Bitrix\B24Api;

final class DealResolver
{
    public function __construct(private readonly B24Api $api)
    {
    }

    /** Идентификатор сделки, к которой привязан чат открытой линии, или null. */
    public function resolve(int $chatId): ?int
    {
        $dialog = $this->api->getOpenLineDialog($chatId);

        $type = strtoupper($this->pick($dialog, ['crm_entity_type', 'CRM_ENTITY_TYPE', 'entityType']));
        $entityId = (int) $this->pick($dialog, ['crm_entity_id', 'CRM_ENTITY_ID', 'entityId']);

        if ($type === 'DEAL' && $entityId > 0) {
            return $entityId;
        }

        // imopenlines.dialog.get не документирует отдельное поле с привязкой к CRM; на практике
        // она лежит в недокументированном entity_data_2: плоский список "TYPE|ID|TYPE|ID|..."
        // по всем типам сразу (например "LEAD|0|COMPANY|0|CONTACT|5329|DEAL|5547"),
        // где 0 означает, что сущность этого типа не привязана.
        $parts = explode('|', (string) ($dialog['entity_data_2'] ?? ''));

        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            if (strtoupper($parts[$i]) === 'DEAL' && (int) $parts[$i + 1] > 0) {
                return (int) $parts[$i + 1];
            }
        }

        return null;
    }

    /** @param string[] $keys */
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
