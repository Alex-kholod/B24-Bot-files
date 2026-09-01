<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use B24DocsBot\Application;
use B24DocsBot\Config;

$app = new Application(Config::fromFile(__DIR__ . '/../config.php'));
$applied = $app->database()->migrate();

echo "Применено миграций: {$applied}\n";
exit(0);
