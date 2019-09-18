<?php

require __DIR__ . '/src/kernel.php';
require __DIR__ . '/src/routes.php';

/* Bootstrap application */
$app = boot();

/* Register web routes for your application */
routes($app);

/* Run application */
$app->run();
