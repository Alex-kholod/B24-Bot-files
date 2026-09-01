<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use B24DocsBot\Application;
use B24DocsBot\Config;

// Два тика cron не должны обрабатывать очередь одновременно: сама по себе аренда строк
// (PendingFileRepository::claim) уже не даёт им забрать одну и ту же запись, но без блокировки
// оба процесса тратили бы время на конкурентный обход due(), и при заминке одного тика мог бы
// начаться следующий. Файловая блокировка исключает это полностью. Пересекающийся тик — штатная
// ситуация (предыдущий ещё не закончил), а не ошибка, поэтому просто тихо выходим с кодом 0.
$lockDir = __DIR__ . '/../var';
if (!is_dir($lockDir)) {
    mkdir($lockDir, 0755, true);
}

$lockHandle = fopen($lockDir . '/retry.lock', 'c');
if ($lockHandle === false) {
    fwrite(STDERR, "Не удалось открыть файл блокировки\n");
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Предыдущий запуск retry.php ещё выполняется, пропускаем этот тик\n";
    exit(0);
}

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
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
