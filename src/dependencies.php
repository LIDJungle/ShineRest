<?php

// Create a dependency injection container
$container = $app->getContainer();

// Inject monolog for logging.
$container['logger'] = function ($c) {
    $settings = $c->get('settings')['logger'];
    $logger = new \Monolog\Logger($settings['name']);
    $logger->pushProcessor(new Monolog\Processor\UidProcessor());
    $logger->pushHandler(new Monolog\Handler\StreamHandler($settings['path'], $settings['level']));
    return $logger;
};

// Inject DB connection using PDO
$container['db'] = function ($c) {
    $db = $c['settings']['db'];
    $pdo = new PDO("mysql:host=" . $db['host'] . ";dbname=" . $db['dbname'],
        $db['user'], $db['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
};

// Set up services
$container['player'] = function ($c) {
    $srv = new Player($c);
    return $srv;
};
$container['pop'] = function ($c) {
    $srv = new POP($c);
    return $srv;
};
$container['display'] = function ($c) {
    $srv = new Display($c);
    return $srv;
};
$container['presentation'] = function ($c) {
    $srv = new Presentation($c);
    return $srv;
};