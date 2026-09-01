<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use B24DocsBot\Application;
use B24DocsBot\Config;

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

http_response_code(200);
echo 'OK';
