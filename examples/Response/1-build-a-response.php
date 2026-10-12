<?php

use phasync\Psr\Response;

$response = new Response(404, ['Content-Type' => 'text/plain'], 'Not found');
echo $response->getStatusCode(), ' ', $response->getReasonPhrase(), "\n";
echo $response->getHeaderLine('content-type'), "\n";
echo $response->getBody(), "\n";

$json = (new Response())
    ->withHeader('Content-Type', 'application/json')
    ->withBody(phasync\Psr\StreamFactory::create(json_encode(['ok' => true])));
echo $json->getStatusCode(), ' ', $json->getReasonPhrase(), ' ', $json->getBody(), "\n";
