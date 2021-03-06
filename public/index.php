<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require '../vendor/autoload.php';

// Instantiate the app
$settings = require __DIR__ . '/../src/settings.php';
$app = new \Slim\App($settings);

// Register services
require_once __DIR__ . '/../src/services/player.php';
require_once __DIR__ . '/../src/services/display.php';
require_once __DIR__ . '/../src/services/pop.php';
require_once __DIR__ . '/../src/services/presentation.php';
require_once __DIR__ . '/../src/services/item.php';

// Set up dependencies
require __DIR__ . '/../src/dependencies.php';

// Register routes
require __DIR__ . '/../src/routes.php';

$app->run();