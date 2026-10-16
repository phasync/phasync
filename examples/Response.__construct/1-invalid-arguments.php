<?php

use phasync\Psr\Response;

try {
    new Response(99);
} catch (InvalidArgumentException $e) {
    echo $e->getMessage(), "\n";
}

try {
    new Response(200, ['Content-Length' => 5]);
} catch (InvalidArgumentException $e) {
    echo $e->getMessage(), "\n";
}

echo (new Response(200, ['Content-Length' => (string) 5]))->getHeaderLine('Content-Length'), "\n";
