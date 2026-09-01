<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use B24DocsBot\Application;
use B24DocsBot\Config;

// Конфигурация и сборка приложения тоже могут упасть (нет config.php, нет прав на var/),
// и без этой границы скрипт вывалился бы трассировкой с кодом 255 вместо внятной ошибки.
try {
    $config = Config::fromFile(__DIR__ . '/../config.php');
    $app = new Application($config);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    exit(1);
}

$portal = $app->tokens()->find();

if ($portal === null) {
    fwrite(STDERR, "Приложение не установлено: сначала откройте install.php из Битрикс24.\n");
    exit(1);
}

try {
    // imbot.v2.Bot.register идемпотентен по code: повторный вызов вернёт существующего бота.
    $botId = $app->api()->registerBot([
        'code' => $config->string('bot_code'),
        'botToken' => $config->string('bot_token'),
        'type' => 'openline',
        'isSupportOpenline' => true,
        'eventMode' => 'webhook',
        'webhookUrl' => $config->string('handler_url'),
        'properties' => ['name' => $config->string('bot_name')],
    ]);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Ошибка регистрации бота: ' . $exception->getMessage() . "\n");
    exit(1);
}

$app->tokens()->saveBot((string) $portal['member_id'], $botId, $config->string('bot_code'));

echo "Бот зарегистрирован, id={$botId}, webhookUrl={$config->string('handler_url')}\n";
echo "Не забудьте подключить бота к открытой линии в её настройках.\n";
exit(0);
