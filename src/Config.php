<?php

declare(strict_types=1);

namespace B24DocsBot;

use InvalidArgumentException;
use RuntimeException;

final class Config
{
    private const REQUIRED = [
        'client_id',
        'client_secret',
        'scope',
        'bot_code',
        'bot_name',
        'bot_token',
        'handler_url',
        'default_responsible_id',
        'db_path',
        'log_path',
    ];

    private const DEFAULTS = [
        'checklist_title' => 'Документы от клиента',
        'max_attempts' => 10,
        'task_group_id' => 0,
        'log_level' => 'info',
    ];

    private function __construct(private readonly array $values)
    {
    }

    public static function fromArray(array $values): self
    {
        $values += self::DEFAULTS;

        $missing = [];
        foreach (self::REQUIRED as $key) {
            if (!isset($values[$key]) || $values[$key] === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Не заданы обязательные параметры конфигурации: ' . implode(', ', $missing)
            );
        }

        return new self($values);
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("Файл конфигурации не найден: {$path}");
        }

        $values = require $path;

        if (!is_array($values)) {
            throw new RuntimeException("Файл конфигурации должен возвращать массив: {$path}");
        }

        return self::fromArray($values);
    }

    public function string(string $key): string
    {
        return (string) ($this->values[$key] ?? '');
    }

    public function int(string $key): int
    {
        return (int) ($this->values[$key] ?? 0);
    }
}
