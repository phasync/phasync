<?php

use phasync\ContextUsedException;

$context = new stdClass();
phasync::run(fn () => print("first run\n"), [], $context);

try {
    phasync::run(fn () => print("never printed\n"), [], $context);
} catch (ContextUsedException $e) {
    echo $e->getMessage(), "\n";
}
