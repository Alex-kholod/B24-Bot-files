<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use B24DocsBot\Application;
use B24DocsBot\Config;

try {
    $app = new Application(Config::fromFile(__DIR__ . '/../config.php'));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    if (in_array('--failed', $argv, true)) {
        $count = $app->pending()->requeueFailed($now);
        echo "Возвращено в очередь: {$count}\n";
    }

    $handler = $app->messageHandler();
    $done = 0;
    $failed = 0;

    foreach ($app->pending()->due($now) as $row) {
        if ($handler->processRow($row, $now)) {
            ++$done;
        } else {
            ++$failed;
        }
    }

    echo "Обработано: {$done}, отложено: {$failed}\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
