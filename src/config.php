<?php

return [
    'paths' => [
        'migrations' => [ __DIR__ . '/migrations' ],
        'log' => __DIR__ . '/../app.log',
    ],
    'db' => [
        'driver'    => 'mysql',
        'host'      => 'mysql',
        'port'      => 3306,
        'database'  => 'ms_database',
        'username'  => 'ms_user',
        'password'  => 'ms_password',
        'charset'   => 'utf8',
        'collation' => 'utf8_unicode_ci',
        'prefix'    => '',
    ],
    'redis' => [
        'host' => 'redis',
        'port' => 6379,
    ],
];
