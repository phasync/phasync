<?php

phasync::run(function () {
    $run = phasync::getContext();

    phasync::withContext(function () use ($run) {
        $request = phasync::getContext();
        echo 'a context entered from the run is its own root: ', var_export(phasync::getRootContext() === $request, true), "\n";

        phasync::withContext(function () use ($request) {
            echo 'a context nested deeper shares that root: ', var_export(phasync::getRootContext() === $request, true), "\n";
        }, new stdClass());
    }, new stdClass());

    echo 'the run is its own root: ', var_export(phasync::getRootContext() === $run, true), "\n";
});
