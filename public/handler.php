<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use B24DocsBot\Application;
use B24DocsBot\Config;

try {
    $app = new Application(Config::fromFile(__DIR__ . '/../config.php'));
    $router = $app->eventRouter();

    if (!$router->verify($_POST)) {
        $app->logger()->warning('Отклонён запрос с неверным application_token');
        http_response_code(403);
        exit;
    }

    $event = $router->parse($_POST);

    if ($event !== null) {
        $app->messageHandler()->handle($event, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }
} catch (Throwable $exception) {
    if (isset($app)) {
        $app->logger()->error('Необработанная ошибка при обработке события бота', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    } else {
        error_log('b24-docs-bot handler.php: ' . $exception::class . ': ' . $exception->getMessage());
    }
}

http_response_code(200);
echo 'OK';
