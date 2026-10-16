<?php

use phasync\Psr\ServerRequest;
use phasync\Psr\StringStream;

$request = new ServerRequest(
    'POST',
    '/login?next=/home',
    new StringStream('user=ann&remember=1'),
    ['Host' => 'example.com', 'Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => 'theme=dark'],
    serverParams: ['REMOTE_ADDR' => '203.0.113.7', 'HTTPS' => 'on'],
    cookieParams: ['theme' => 'dark'],
    parsedBody: ['user' => 'ann', 'remember' => '1'],
);

echo $request->getUri(), "\n";                  // https: from the server params
print_r($request->getQueryParams());
print_r($request->getParsedBody());
print_r($request->getCookieParams());
echo $request->getServerParams()['REMOTE_ADDR'], "\n";
