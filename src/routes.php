<?php

/**
 * Slim3 routing file
 * Each route contains a 200 (good), 400 (you screwed up), and 500 (i screwed up) case.
 */

/** Heartbeat
 * public/getSchedule?displayId=100002&version=1.0&reboot=false&previewMode=true
 *  Required: displayId
 */
$app->get('/getSchedule', function ($request, $response, $args) {
    $id = $request->getParam('displayId');
    $version = $request->getParam('version');
    $mode = $request->getParam('previewMode');
    $reboot = $request->getParam('reboot');
    if ($id === null) {
        $r['stat'] = 'error';
        $r['message'] = "DisplayId was not specified. Cannot return schedule.";
        return $response->withJson($r, 400);
    }

    $player = $this->get('player');

    $schedule = $player->getSchedule($id, $version, $mode, $reboot);
    if ($schedule['stat'] === 'error') {
        $newResponse = $response->withJson($schedule, 500);
    } else {
        $newResponse = $response->withJson($schedule, 200);
    }

    return $newResponse;
});

/**
 * Display parameters for player
 * public/getDisplay?displayId=100002
 * Required: displayId
 */
$app->get('/getDisplay', function ($request, $response, $args) {
    $id = $request->getParam('displayId');
    if ($id === null) {
        $r['stat'] = 'error';
        $r['message'] = "DisplayId was not specified. Cannot return display parameters.";
        return $response->withJson($r, 400);
    }

    $display = $this->get('display');

    $params = $display->getDisplayParam($id);
    if ($params['stat'] === 'error') {
        $newResponse = $response->withJson($params, 500);
    } else {
        $newResponse = $response->withJson($params, 200);
    }
    return $newResponse;
});

/**
 * Store proof of play
 * public/storePop
 * Required 2 parameters as POST args:
 *  displayId
 *  pop - an array of proof of play data.
 */
$app->post('/storePop', function ($request, $response, $args) {
    $id = $request->getParam('displayId');
    $data = $request->getParam('pop');
    if ($id === null) {
        $r['stat'] = 'error';
        $r['message'] = "DisplayId was not specified. Cannot store Proof of Play data.";
        return $response->withJson($r, 400);
    }
    if ($data === null) {
        $r['stat'] = 'error';
        $r['message'] = "No data sent to server. Nothing to store.";
        return $response->withJson($r, 400);
    }

    $pop = $this->get('pop');

    $stat = $pop->storePop($id, $data);
    if ($stat['stat'] === 'error') {
        $newResponse = $response->withJson($stat, 500);
    } else {
        $newResponse = $response->withJson($stat, 200);
    }
    return $newResponse;
});

$app->any('/ping', function ($request, $response, $args) {
    return $response;
});