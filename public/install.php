<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use B24DocsBot\Application;
use B24DocsBot\Config;

$config = Config::fromFile(__DIR__ . '/../config.php');
$app = new Application($config);

$memberId = (string) ($_REQUEST['member_id'] ?? '');
$accessToken = (string) ($_REQUEST['AUTH_ID'] ?? '');
$refreshToken = (string) ($_REQUEST['REFRESH_ID'] ?? '');
$domain = (string) ($_REQUEST['DOMAIN'] ?? '');

if ($memberId === '' || $accessToken === '' || $domain === '') {
    http_response_code(400);
    echo 'Страница открывается только из мастера установки Битрикс24.';
    exit;
}

$error = null;
$tokensSaved = false;
$botId = null;

try {
    $app->tokens()->save([
        'member_id' => $memberId,
        'domain' => $domain,
        'client_endpoint' => "https://{$domain}/rest/",
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_at' => time() + (int) ($_REQUEST['AUTH_EXPIRES'] ?? 3600),
        'application_token' => (string) ($_REQUEST['auth']['application_token'] ?? ''),
        'installed_by_user_id' => (int) ($_REQUEST['auth']['user_id'] ?? 0),
    ]);
    $tokensSaved = true;

    $botId = $app->api()->registerBot([
        'code' => $config->string('bot_code'),
        'botToken' => $config->string('bot_token'),
        'type' => 'openline',
        'isSupportOpenline' => true,
        'eventMode' => 'webhook',
        'webhookUrl' => $config->string('handler_url'),
        'properties' => ['name' => $config->string('bot_name')],
    ]);

    $app->tokens()->saveBot($memberId, $botId, $config->string('bot_code'));
    $app->logger()->info('Приложение установлено', ['member_id' => $memberId, 'bot_id' => $botId]);
} catch (Throwable $exception) {
    $error = $exception->getMessage();

    if ($tokensSaved) {
        $app->logger()->error('Не удалось зарегистрировать бота', ['error' => $error]);
    } else {
        $app->logger()->error('Не удалось сохранить токены авторизации портала', ['error' => $error]);
    }
}

?>
<!doctype html>
<meta charset="utf-8">
<script src="//api.bitrix24.com/api/v1/"></script>
<h1>Установка бота «Документы клиента»</h1>
<?php if ($error === null): ?>
    <p>Бот зарегистрирован. Идентификатор: <?= (int) $botId ?>.</p>
    <p><strong>Остался один шаг вручную:</strong> подключите бота к нужной открытой линии
       в её настройках, иначе он не будет получать сообщения.</p>
<?php elseif ($tokensSaved): ?>
    <p>Токены сохранены, но зарегистрировать бота не удалось:</p>
    <pre><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></pre>
    <p>Исправьте причину и выполните <code>php bin/install_bot.php</code> на сервере.</p>
<?php else: ?>
    <p>Не удалось сохранить токены авторизации портала — установка не завершена:</p>
    <pre><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></pre>
    <p>Повторите установку приложения заново из маркетплейса Битрикс24.</p>
<?php endif; ?>
<script>BX24.installFinish();</script>
