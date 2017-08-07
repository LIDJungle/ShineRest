<?php
// Routes

$app->get('/heartbeat', function ($request, $response, $args) {
    $id = $request->getParam('displayId');
    $version = $request->getParam('version');
    $mode = $request->getParam('previewMode');
    $reboot = $request->getParam('reboot');
    $player = $this->get('player');

    $schedule = $player->getSchedule($id, $version, $mode, $reboot);
    $newResponse = $response->withJson($schedule, 200);
    return $newResponse;
});

$app->get('/displayparam', function ($request, $response, $args) {
    $id = $request->getParam('displayId');
    $display = $this->get('display');

    $params = $display->getDisplayParam($id);
    $newResponse = $response->withJson($params, 200);
    return $newResponse;
});

$app->post('/storepop', function ($request, $response, $args) {
    $id = $request->getParam('displayId');
    $data = $request->getParam('pop');
    $pop = $this->get('pop');

    $stat = $pop->storePop($id, $data);
    $newResponse = $response->withJson($stat, 200);
    return $newResponse;
});