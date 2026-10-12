<?php

phasync::run(function () {
    $configChanged = new stdClass();

    foreach (['router', 'cache', 'logger'] as $component) {
        phasync::go(function () use ($configChanged, $component) {
            phasync::awaitFlag($configChanged);
            echo "$component reloads\n";
        });
    }

    echo phasync::raiseFlag($configChanged), " woken\n";
    echo phasync::raiseFlag($configChanged), " woken: nobody waits any more\n";
});
