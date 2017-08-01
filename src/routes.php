<?php
// Routes

$app->get('/', function ($request, $response, $args) {
    // Sample log message
    $this->logger->info("Slim-Skeleton '/' route");

    $this->logger->info("getting player service");
    $player = $this->get('player');

    // Render index view
    $data = $player->getPlayer();
    var_dump($data);
});