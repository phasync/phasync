<?php

use phasync\Psr\Request;
use phasync\Psr\Uri;

$request = new Request('GET', '/status', '', ['Host' => 'example.com:8080']);
echo $request->getUri(), "\n";                              // derived from the target and Host

// withUri() sets the URI and, as PSR-7 says, the Host header; the request target stays
$moved = $request->withUri(new Uri('https://backend.internal/health'));
echo $moved->getUri(), "\n";
echo $moved->getRequestTarget(), ' ', $moved->getHeaderLine('Host'), "\n";

// With $preserveHost, the Host header stays
$kept = $request->withUri(new Uri('https://backend.internal/health'), true);
echo $kept->getHeaderLine('Host'), "\n";
