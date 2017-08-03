<?php
// Routes

$app->get('/', function ($request, $response, $args) {
    $id = $request->getParam('displayId');
    $version = $request->getParam('version');

    $this->logger->info("getting player service with ".$id);
    $player = $this->get('player');

    // Render index view
    //$data = $player->getPlayer();
    //var_dump($data);

    // Echo display owner
    $schedule = $player->getSchedule($id, $version);
    echo "<br><br>Schedule: <br> ".$schedule;
});