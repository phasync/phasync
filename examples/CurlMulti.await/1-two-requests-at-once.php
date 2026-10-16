<?php

use phasync\Services\CurlMulti;

// file:// URLs stand in for http:// ones, so the example needs no network
$fetch = static function (string $url): string {
    $ch = \curl_init($url);
    \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);

    return CurlMulti::await($ch);
};

phasync::run(function () use ($fetch) {
    $a = phasync::go(fn () => $fetch('file://' . __FILE__));
    $b = phasync::go(fn () => $fetch('file://' . __DIR__ . '/../../composer.json'));

    echo \strlen(phasync::await($a)), ' and ', \strlen(phasync::await($b)), " bytes fetched\n";
});
