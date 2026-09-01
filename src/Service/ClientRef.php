<?php

declare(strict_types=1);

namespace B24DocsBot\Service;

final class ClientRef
{
    public const BINDING_PREFIXES = [
        'LEAD' => 'L',
        'CONTACT' => 'C',
        'COMPANY' => 'CO',
        'DEAL' => 'D',
    ];

    public function __construct(
        public readonly string $crmEntityType,
        public readonly int $crmEntityId,
        public readonly string $title,
    ) {
    }

    public function clientKey(): string
    {
        return sprintf('crm:%s:%d', $this->crmEntityType, $this->crmEntityId);
    }

    public function crmBinding(): string
    {
        return self::BINDING_PREFIXES[$this->crmEntityType] . '_' . $this->crmEntityId;
    }
}
