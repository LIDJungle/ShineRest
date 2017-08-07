<?php
// Routes

$app->get('/heartbeat', function ($request, $response, $args) {
    $id = $request->getParam('displayId');
    $version = $request->getParam('version');
    $mode = $request->getParam('previewMode');
    $player = $this->get('player');

    $schedule = $player->getSchedule($id, $version, $mode);
    $newResponse = $response->withJson($schedule, 201);
    return $newResponse;
});

$app->get('/displayparam', function ($request, $response, $args) {
    $id = $request->getParam('displayId');
    $display = $this->get('display');

    $params = $display->getDisplayParam($id);
    $newResponse = $response->withJson($params, 201);
    return $newResponse;
});

$app->post('/storepop', function ($request, $response, $args) {
    $id = $request->getParam('displayId');
    $data = $request->getParam('pop');
    $pop = $this->get('pop');

    $stat = $pop->storePop($id, $data);
    $newResponse = $response->withJson($stat, 201);
    return $newResponse;
});