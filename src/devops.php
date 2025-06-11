<?php

$app = \Fw\App::getInstance();
$app->beforeRoute(function (\Fw\Request $request, \Fw\App $app) {
    $pathInfo = trim($request->getOriginPathInfo(), '/');
    switch ($pathInfo) {
        case 'devops/status':
            //http code:200
            die;
            break;
        case 'devops/version':
            //http code:200, response json
            echo json_encode([
                'version' => '1.0.0'
            ]);
            die;
            break;
    }
});