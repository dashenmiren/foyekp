<?php

use Phalcon\Di\FactoryDefault;
use Phalcon\Loader;
use Phalcon\Mvc\Application;
use Phalcon\Mvc\View;
use Phalcon\Url;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

$loader = new Loader();
$loader->registerDirs(
    [
        APP_PATH . '/controllers/',
        APP_PATH . '/models/',
    ]
);
$loader->register();

$container = new FactoryDefault();

$loader->registerClasses(
    [
        'ConfigService' => APP_PATH . '/services/ConfigService.php',
        'RedisService' => APP_PATH . '/services/RedisService.php',
        'CacheService' => APP_PATH . '/services/CacheService.php',
        'AccessService' => APP_PATH . '/services/AccessService.php',
        'SiteService' => APP_PATH . '/services/SiteService.php',
        'TemplateEngine' => APP_PATH . '/services/TemplateEngine.php',
        'Context' => APP_PATH . '/services/Context.php',
    ]
);

$container->set(
    'config',
    function () {
        return new ConfigService();
    }
);

$container->set(
    'redis',
    function () {
        return new RedisService();
    }
);

$container->set(
    'cache',
    function () {
        return new CacheService();
    }
);

$container->set(
    'site',
    function () {
        return new SiteService();
    }
);

$container->set(
    'templateEngine',
    function () {
        return new TemplateEngine();
    }
);

$container->set(
    'context',
    function () {
        return new Context();
    }
);

$container->set(
    'view',
    function () {
        $view = new View();
        $view->setViewsDir(APP_PATH . '/views/');
        return $view;
    }
);

$container->set(
    'url',
    function () {
        $url = new Url();
        $url->setBaseUri('/');
        return $url;
    }
);

$application = new Application($container);

try {
    $response = $application->handle(
        $_SERVER["REQUEST_URI"]
    );

    $response->send();
} catch (\Exception $e) {
    echo 'Exception: ', $e->getMessage();
}
