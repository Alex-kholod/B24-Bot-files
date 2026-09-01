<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use B24DocsBot\Application;
use B24DocsBot\Config;
use B24DocsBot\Service\ChecklistWriter;

$config = Config::fromFile(__DIR__ . '/../config.php');
$app = new Application($config);

echo "1. Конфигурация прочитана, обязательные значения на месте.\n";

$portal = $app->tokens()->find();

if ($portal === null) {
    fwrite(STDERR, "2. Токены портала не найдены — приложение не установлено.\n");
    exit(1);
}

echo "2. Портал: {$portal['domain']}, установил пользователь {$portal['installed_by_user_id']}.\n";

try {
    $botId = $app->api()->registerBot([
        'code' => $config->string('bot_code'),
        'botToken' => $config->string('bot_token'),
        'type' => 'openline',
        'isSupportOpenline' => true,
        'eventMode' => 'webhook',
        'webhookUrl' => $config->string('handler_url'),
        'properties' => ['name' => $config->string('bot_name')],
    ]);

    echo "3. Связь с порталом есть, бот доступен: id={$botId}.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, '3. Портал недоступен или бот не зарегистрирован: ' . $exception->getMessage() . "\n");
    exit(1);
}

if (in_array('--recheck-attachments', $argv, true)) {
    $app->settings()->delete(ChecklistWriter::SETTING_KEY);
    echo "4. Флаг поддержки ATTACHMENTS сброшен: режим определится при следующей записи.\n";
} else {
    $flag = $app->settings()->get(ChecklistWriter::SETTING_KEY);
    $text = match ($flag) {
        '1' => 'файлы кладутся прямо в пункт чек-листа',
        '0' => 'файлы цепляются к задаче, в пункт пишется ссылка',
        default => 'ещё не определён',
    };
    echo "4. Режим чек-листа: {$text}.\n";
}

$counts = $app->database()->pdo()
    ->query('SELECT status, COUNT(*) AS total FROM pending_files GROUP BY status')
    ->fetchAll(PDO::FETCH_KEY_PAIR);

$new = (int) ($counts['new'] ?? 0);
$done = (int) ($counts['done'] ?? 0);
$failed = (int) ($counts['failed'] ?? 0);

echo "5. Очередь файлов: в работе {$new}, обработано {$done}, с ошибкой {$failed}.\n";

exit(0);
