<?php

phasync::run(function () {
    $fiber = phasync::go(function () {
        echo "child runs up to its first wait\n";
        phasync::sleep(0.01);
        echo "child resumes\n";

        return 42;
    });
    echo "go() returned\n";
    $answer = phasync::await($fiber);
    echo "await() returned $answer\n";
});
