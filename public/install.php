<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use B24DocsBot\Application;
use B24DocsBot\Config;

$config = Config::fromFile(__DIR__ . '/../config.php');
$app = new Application($config);

// Современные порталы присылают установку как событие ONAPPINSTALL: весь OAuth-грант
// (access_token, refresh_token, member_id, user_id, expires, application_token,
// domain, client_endpoint) лежит во вложенном auth, а не в корне запроса. Старый
// протокол слал те же данные плоскими полями (member_id, AUTH_ID, REFRESH_ID,
// DOMAIN, AUTH_EXPIRES) — оставляем как запасной путь для более старых порталов.
$authData = is_array($_REQUEST['auth'] ?? null) ? $_REQUEST['auth'] : [];

$memberId = (string) ($authData['member_id'] ?? $_REQUEST['member_id'] ?? '');
$accessToken = (string) ($authData['access_token'] ?? $_REQUEST['AUTH_ID'] ?? '');
$refreshToken = (string) ($authData['refresh_token'] ?? $_REQUEST['REFRESH_ID'] ?? '');
$domain = (string) ($authData['domain'] ?? $_REQUEST['DOMAIN'] ?? '');
$applicationToken = (string) ($authData['application_token'] ?? '');
$installedByUserId = (int) ($authData['user_id'] ?? 0);
$clientEndpoint = (string) ($authData['client_endpoint'] ?? ($domain !== '' ? "https://{$domain}/rest/" : ''));
$expiresAt = isset($authData['expires'])
    ? (int) $authData['expires']
    : time() + (int) ($_REQUEST['AUTH_EXPIRES'] ?? 3600);

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
        'client_endpoint' => $clientEndpoint,
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_at' => $expiresAt,
        'application_token' => $applicationToken,
        'installed_by_user_id' => $installedByUserId,
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
        $app->logger()->error('Не удалось зарегистрировать бота', ['exception' => $exception::class, 'error' => $error]);
    } else {
        $app->logger()->error('Не удалось сохранить токены авторизации портала', ['exception' => $exception::class, 'error' => $error]);
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
