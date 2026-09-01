<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use B24DocsBot\Config;
use B24DocsBot\Storage\Database;

try {
    $config = Config::fromFile(__DIR__ . '/../config.php');
    $database = new Database($config->string('db_path'));
    $applied = $database->migrate();

    echo "Применено миграций: {$applied}\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
