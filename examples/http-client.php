<?php
require(__DIR__ . '/../vendor/autoload.php');

use phasync\HttpClient\HttpClient;

/**
 * This example does in no way seem to be concurrent, but
 * the HTTP client internally uses a coroutine context,
 * making the example async
 */
phasync::run(function() {
    $client = new HttpClient();
    echo "Fetching concurrently:\n";
    $a = $client->get("https://www.vg.no/");
    $b = $client->get("https://www.microsoft.com/");
    echo "VG.no: " . strlen($a->getBody()) . "\n";
    echo "Microsoft.com: " . strlen($b->getBody()) . "\n";
});
