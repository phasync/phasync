<?php

use phasync\Psr\ServerRequest;

$request = new ServerRequest('GET', '/users/42', null);

// A router adds what it matched; the handler reads it
$routed = $request->withAttribute('route', 'user.show')->withAttribute('id', '42');

echo $routed->getAttribute('route'), ' ', $routed->getAttribute('id'), "\n";
echo $routed->getAttribute('missing', 'default'), "\n";
print_r($request->getAttributes());              // the original has none
print_r($routed->withoutAttribute('route')->getAttributes());
