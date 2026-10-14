<?php

// A request's coroutines run in the request's Scope, also after they waited, while other
// requests run in theirs: per-request state hangs on Scope::getCurrent().
$users = new WeakMap();   // Scope => user

phasync::run(function () use ($users) {
    foreach (['ann', 'bob'] as $user) {
        $request         = new Scope();
        $users[$request] = $user;
        $request->run(function () use ($users) {
            phasync::go(function () use ($users) {
                phasync::sleep(0.01);
                echo 'serving ', $users[Scope::getCurrent()], "\n";
            });
        });
    }
});
