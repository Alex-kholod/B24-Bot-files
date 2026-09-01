<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use B24DocsBot\Application;
use B24DocsBot\Config;

try {
    $app = new Application(Config::fromFile(__DIR__ . '/../config.php'));
} catch (Throwable $exception) {
    // Приложение не поднялось — очередь недоступна, поэтому обычное правило
    // "всегда 200" здесь не действует: запросить повтор доставки, кроме
    // Битрикс24, некому. Отдаём 503 с Retry-After, чтобы повтор пришёл сам.
    error_log('b24-docs-bot handler.php: ' . $exception::class . ': ' . $exception->getMessage());
    http_response_code(503);
    header('Retry-After: 60');
    exit;
}

$router = $app->eventRouter();

if (!$router->verify($_POST)) {
    http_response_code(403);

    try {
        $app->logger()->warning('Отклонён запрос с неверным application_token');
    } catch (Throwable $loggingException) {
        error_log('b24-docs-bot handler.php: ' . $loggingException::class . ': ' . $loggingException->getMessage());
    }

    exit;
}

try {
    $event = $router->parse($_POST);

    if ($event !== null) {
        $app->messageHandler()->handle($event, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }
} catch (Throwable $exception) {
    try {
        $app->logger()->error('Необработанная ошибка при обработке события бота', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    } catch (Throwable $loggingException) {
        error_log('b24-docs-bot handler.php: ' . $loggingException::class . ': ' . $loggingException->getMessage());
    }
}

http_response_code(200);
echo 'OK';
