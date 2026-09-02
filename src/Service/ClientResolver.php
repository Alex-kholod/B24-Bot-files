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
            [$type, $entityId] = $this->parseEntityDataBinding($dialog);
        }

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

    /**
     * imopenlines.dialog.get не документирует отдельное поле с типом и id привязанной
     * CRM-сущности. Единственный источник этих данных на практике — недокументированное
     * поле entity_data_2: плоский список "TYPE|ID|TYPE|ID|..." по всем типам сразу
     * (например "LEAD|0|COMPANY|0|CONTACT|5323|DEAL|5541"), где 0 означает, что сущность
     * этого типа не создана. К одному чату открытой линии одновременно могут быть
     * привязаны и контакт, и сделка — тогда предпочитаем контакт: он держит идентичность
     * клиента при смене канала связи, а сделка меняется от обращения к обращению.
     *
     * @return array{0: string, 1: int}
     */
    private function parseEntityDataBinding(array $dialog): array
    {
        $raw = (string) ($dialog['entity_data_2'] ?? '');

        if ($raw === '') {
            return ['', 0];
        }

        $parts = explode('|', $raw);
        $bound = [];

        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $bound[strtoupper($parts[$i])] = (int) $parts[$i + 1];
        }

        foreach (['CONTACT', 'COMPANY', 'DEAL', 'LEAD'] as $preferred) {
            if (($bound[$preferred] ?? 0) > 0) {
                return [$preferred, $bound[$preferred]];
            }
        }

        return ['', 0];
    }
}
