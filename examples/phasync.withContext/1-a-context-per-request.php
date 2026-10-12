<?php

// A server runs each request in a context of its own, in the coroutine that accepted it
function handle(string $path): string
{
    phasync::go(function () use ($path) {
        phasync::sleep(0.01);
        echo "audit log written for $path\n";
    });

    return "200 OK $path";
}

phasync::run(function () {
    foreach (['/home', '/about'] as $path) {
        $request  = new stdClass();
        $response = phasync::withContext(fn () => handle($path), $request);
        // withContext() returned after the coroutines the request started
        echo "$response\n";
    }
});
