<?php

use phasync\Util\Synchronized;

phasync::run(function () {
    // Different tokens do not wait for each other
    $user = new stdClass();
    phasync::go(fn () => Synchronized::run($user, function () {
        phasync::sleep(0.02);
        echo "done with the user object\n";
    }));
    phasync::go(fn () => Synchronized::run('settings', function () {
        echo "done with settings\n";
    }));

    // The result of the closure is returned
    echo Synchronized::run('counter', fn () => 'returned: 42'), "\n";

    // Not reentrant: the holder asking again throws
    try {
        Synchronized::run('counter', fn () => Synchronized::run('counter', fn () => null));
    } catch (LogicException $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
});
