<?php

/**
 * Copyright (c) 2024 Frode Børli. Released under the MIT License.
 */

require __DIR__ . '/../vendor/autoload.php';

use phasync\HttpClient\HttpClient;
use Psr\Http\Message\ResponseInterface;

/*
 * Warning! The HttpClient here is a proof of concept. I want
 * it rewritten to be compatible with PSR, but it demonstrates
 * the {@see phasync\Services\CurlMulti} service in cooperation
 * with the {@see phasync\HttpClient\HttpClient} generating
 * PSR-7 ResponseInterface and PSR-7 StreamInterface.
 *
 * This example does in no way appears to be concurrent, but
 * the HTTP client internally uses a coroutine context,
 * making the example async
 */
phasync::run(function () {
    $client = new HttpClient();

    /**
     * @var array<array{0: string, 1: ?ResponseInterface}>
     */
    $requests = [
        ['https://www.vg.no/', null],
        ['https://www.microsoft.com/', null],
        ['https://www.db.no/', null],
    ];
    foreach ($requests as [$url, &$response]) {
        echo "GET $url\n";
        $response = $client->get($url);
    }
    foreach ($requests as [$url, $response]) {
        echo "$url size: " . \mb_strlen($response->getBody()) . "\n";
    }
});
