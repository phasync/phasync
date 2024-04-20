<?php

use phasync\Loop;

use function phasync\run;

require("../vendor/autoload.php");

/**
 * Asynchronous HTTP Client function with Loop::yield()
 */
function asyncHttpClient(array $urls): array {
    $multiHandle = curl_multi_init();
    $curlHandles = [];
    $responses = [];

    foreach ($urls as $url) {
        $curlHandle = curl_init($url);
        curl_setopt_array($curlHandle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10, // Set your desired timeout
        ]);
        curl_multi_add_handle($multiHandle, $curlHandle);
        $curlHandles[] = $curlHandle;
    }

    do {
        // Execute the curl multi-handle
        curl_multi_exec($multiHandle, $active);

        // Yield control to allow other coroutines to execute
        Loop::yield();
    } while ($active > 0);

    foreach ($curlHandles as $curlHandle) {
        $response = curl_multi_getcontent($curlHandle);
        $responses[] = $response;
        curl_multi_remove_handle($multiHandle, $curlHandle);
        curl_close($curlHandle);
    }

    curl_multi_close($multiHandle);

    return $responses;
}

phasync\run(function() {
    $results = asyncHttpClient(['http://www.vg.no/', 'http://www.db.no']);
    var_dump($results);
});

