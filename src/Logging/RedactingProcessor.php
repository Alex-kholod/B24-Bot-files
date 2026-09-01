<?php

declare(strict_types=1);

namespace B24DocsBot\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class RedactingProcessor implements ProcessorInterface
{
    private const SECRET_KEYS = [
        'access_token',
        'refresh_token',
        'application_token',
        'bot_token',
        'bottoken',
        'client_secret',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: $this->redact($record->context));
    }

    private function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                $context[$key] = '***';
                continue;
            }

            if (is_array($value)) {
                $context[$key] = $this->redact($value);
            }
        }

        return $context;
    }
}
