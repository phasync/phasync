<?php

use phasync\Psr\ServerRequest;
use phasync\Psr\StringStream;

$request = new ServerRequest(
    'POST',
    '/upload',
    new StringStream('{"title":"Holiday"}'),
    ['Content-Type' => 'application/json'],
    parsedBody: function () use (&$request) {
        echo "(parsing the body now)\n";

        return json_decode((string) $request->getBody(), true);
    },
);

echo "request made\n";
print_r($request->getParsedBody());              // the closure runs when asked

$replaced = $request->withParsedBody(['title' => 'Work']);
print_r($replaced->getParsedBody());             // with...() replaces the closure
