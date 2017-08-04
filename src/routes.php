<?php
// Routes

$app->get('/heartbeat', function ($request, $response, $args) {
    $id = $request->getParam('displayId');
    $version = $request->getParam('version');
    $player = $this->get('player');

    $schedule = $player->getSchedule($id, $version);
    $newResponse = $response->withJson($schedule, 201);
    return $newResponse;
});

$app->get('/displayparam', function ($request, $response, $args) {
    $id = $request->getParam('displayId');
    $player = $this->get('player');

    $schedule = $player->getDisplayParam($id);
    $newResponse = $response->withJson($schedule, 201);
    return $newResponse;
});

$app->post('/storepop', function ($request, $response, $args) {
    $id = $request->getParam('displayId');
    $data = $request->getParam('pop');
    $pop = $this->get('pop');

    $schedule = $player->getDisplayParam($id);
    $newResponse = $response->withJson($schedule, 201);
    return $newResponse;
});