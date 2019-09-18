<?php

use Middlewares\TrailingSlash;

require __DIR__ . '/middleware.php';
require __DIR__ . '/controller.php';

/* Web Routes */
function routes($app) {
    /* Add middleware to application */
    $app->add(new JsonBodyParserMiddleware());

    /* Route to index page */
    $app->get('/', 'Controller:index');

    /* Route to reservation page not use any lock technique */
    $app->post('/create', 'Controller:noLock');

    /* Route to reservation page use memcached lock technique */
    $app->post('/create/memcached', 'Controller:memcachedLock');

    /* Route to reservation page use redis lock technique */
    $app->post('/create/redis', 'Controller:redisLock');

    /* Route to reservation page use database lock for update technique */
    $app->post('/create/database/lockforupdate', 'Controller:databaseLockForUpdate');

    /* Route to reservation page use database lock for update + insert technique */
    $app->post('/create/database/lockforinsert', 'Controller:databaseLockForInsert');

    /* Route to reservation page use rabbitmq lock technique */
    $app->post('/create/rabitmq', 'Controller:rabbitmqLock');

    /* Trailing / in route patterns */
    $app->add(new TrailingSlash(false));
}
