<?php

phasync::enableContextState();

phasync::run(function () {
    foreach (['ann', 'bob'] as $user) {
        phasync::go(function () use ($user) {
            phasync::$contextState['user'] = $user;
            phasync::sleep(0.01);   // the other request runs meanwhile
            echo 'request of ', phasync::$contextState['user'], "\n";
        }, [], new stdClass());     // a context per request
    }
});
