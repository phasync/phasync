<?php

use phasync\Psr\Response;
use phasync\Psr\StringStream;

$response = new Response(404, ['Content-Type' => 'text/plain'], new StringStream('Not found'));
echo $response->getStatusCode(), ' ', $response->getReasonPhrase(), "\n";
echo $response->getHeaderLine('content-type'), "\n";
echo $response->getBody(), "\n";

$json = (new Response())
    ->withHeader('Content-Type', 'application/json')
    ->withBody(new StringStream(json_encode(['ok' => true])));
echo $json->getStatusCode(), ' ', $json->getReasonPhrase(), ' ', $json->getBody(), "\n";
