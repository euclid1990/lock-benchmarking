<?php

use Slim\App;
use Slim\Factory\AppFactory;
use Psr\Container\ContainerInterface;
use Slim\Views\PhpRenderer as View;
use Monolog\Logger as Logger;
use Monolog\Handler\StreamHandler as StreamHandler;
use Noodlehaus\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Facade;

require __DIR__ . '/../vendor/autoload.php';

/*
 * Because PHP_DI not implement ArrayAccess so we can't call
 * Container object as type array
 * For $container['key'] we implement it as following
 */
class Container extends DI\Container implements ArrayAccess
{
    /**
     * Determine if a given offset exists.
     *
     * @param  string  $key
     * @return bool
     */
    public function offsetExists($key)
    {
        return $this->has($key);
    }
    /**
     * Get the value at a given offset.
     *
     * @param  string  $key
     * @return mixed
     */
    public function offsetGet($key)
    {
        return $this->get($key);
    }
    /**
     * Set the value at a given offset.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return void
     */
    public function offsetSet($key, $value)
    {
        if (! $value instanceof Closure) {
            $value = function () use ($value) {
                return $value;
            };
        }
        $this->set($key, $value);
    }
    /**
     * Unset the value at a given offset.
     *
     * @param  string  $key
     * @return void
     */
    public function offsetUnset($key)
    {
        if (array_key_exists($key, $this->resolvedEntries)) {
            unset($this->resolvedEntries[$key]);
        }
    }
}

/* Create the application */
function boot(): App
{
    /* Load configurations */
    $config = new Config(__DIR__ . '/config.php');

    /* Get the container to add dependencies */
    $container = new Container();

    // Set container to create App with on AppFactory
    AppFactory::setContainer($container);

    /* Create the application */
    $app = AppFactory::create();

    /* Use Config in application */
    $container->set('config', $config);

    /* Use Monolog in application */
    $container->set('log', function(ContainerInterface $c) {
        $cfg = $c->get('config')->get('paths.log');
        $logger = new Logger('app');
        $formatter = new Monolog\Formatter\LineFormatter();
        $formatter->allowInlineLineBreaks();
        $fileHandler = new StreamHandler($cfg);
        $fileHandler->setFormatter($formatter);
        $logger->pushHandler($fileHandler);
        class_alias(Illuminate\Support\Facades\Log::class, 'Log');
        return $logger;
    });

    /* Use PHP views */
    $container->set('view', new View(__DIR__ . '/views/'));

    /* Use Illuminate/events in application */
    $container->set('events', function(ContainerInterface $c) {
        $events = new Illuminate\Events\Dispatcher;
        return $events;
    });

    /* Use Illuminate/filesystem in application */
    $container->set('filesystem', function(ContainerInterface $c) {
        $filesystem = new Illuminate\Filesystem\Filesystem;
        return $filesystem;
    });

    /* Use Illuminate/database in application */
    $container->set('db', function(ContainerInterface $c) {
        $cfg = $c->get('config')->get('db');
        $events = $c->get('events');
        $capsule = new Illuminate\Database\Capsule\Manager;
        $capsule->addConnection($cfg);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->getConnection()->setEventDispatcher($events);
        class_alias(Illuminate\Support\Facades\DB::class, 'DB');
        return $capsule;
    });

    /* Use Illuminate/database/migration/migrator in application */
    $container->set('migrator', function(ContainerInterface $c) {
        $db = $c->get('db');
        $filesystem = $c->get('filesystem');
        $events = $c->get('events');
        $repository = new Illuminate\Database\Migrations\DatabaseMigrationRepository($db->getDatabaseManager(), 'migration');
        $resolver = $db->getDatabaseManager();
        $migrator = new Illuminate\Database\Migrations\Migrator(
            $repository,
            $resolver,
            $filesystem,
            $events
        );
        class_alias(Illuminate\Support\Facades\Schema::class, 'Schema');
        return $migrator;
    });

    /* Set Facade reference to the service container */
    Facade::setFacadeApplication($container);

    /* Enable MySQL query logging */
    DB::connection()->listen(function($query) {
        Log::info('SQL', ['query' => $query->sql, 'bindings' => $query->bindings, 'time' => $query->time]);
    });

    return $app;
}
