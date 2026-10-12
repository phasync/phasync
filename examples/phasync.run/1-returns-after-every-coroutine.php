<?php

$result = phasync::run(function () {
    phasync::go(function () {
        phasync::sleep(0.02);
        echo "background job done\n";
    });
    echo "main coroutine returns\n";

    return 'result';
});

// run() waited for the background job before it returned
echo "run() returned: $result\n";
