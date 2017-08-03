<?php
// Routes

$app->get('/', function ($request, $response, $args) {
    $id = $request->getParam('displayId');

    $this->logger->info("getting player service with ".$id);
    $player = $this->get('player');

    // Render index view
    $data = $player->getPlayer();
    var_dump($data);

    // Echo display owner
    $ownerId = $player->getDisplayOwner($id);
    echo "<br><br>OwnerId is ".$ownerId;
});