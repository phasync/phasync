<?php

use phasync\Psr\Request;
use phasync\Psr\StringStream;

$request = new Request('POST', '/search?q=phasync', new StringStream('{"limit":10}'), [
    'Host'         => 'example.com',
    'Content-Type' => 'application/json',
]);

echo $request->getMethod(), ' ', $request->getRequestTarget(), ' HTTP/', $request->getProtocolVersion(), "\n";
foreach ($request->getHeaders() as $name => $values) {
    echo $name, ': ', implode(', ', $values), "\n";
}
echo "\n", $request->getBody(), "\n\n";

echo 'URI: ', $request->getUri(), "\n";                   // derived from the target and Host
echo 'query: ', $request->getQuery(), "\n";

$copy = $request->withHeader('Content-Type', 'text/plain');   // immutable: a changed copy
echo $request->getHeaderLine('content-type'), ' / ', $copy->getHeaderLine('content-type'), "\n";
