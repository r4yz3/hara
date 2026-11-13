<?php

/*
| PHPUnit bootstrap.
|
| Inside the Docker stack the container already carries real environment
| variables (DB_DATABASE, SESSION_DRIVER, ...). Those outrank the <env> values
| in phpunit.xml, so without this file the suite would run against the
| development database. Every value below is forced in all three places the
| framework reads from.
*/

require __DIR__.'/../vendor/autoload.php';

$forced = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'HASH_DRIVER' => 'bcrypt',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => 'food_delivery_test',
    'DB_URL' => '',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
];

foreach ($forced as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
