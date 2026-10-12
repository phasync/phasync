<?php

use phasync\Psr\Response;

$response = new Response();
foreach ([201, 418, 599] as $code) {
    $copy = $response->withStatus($code);
    echo $copy->getStatusCode(), ' "', $copy->getReasonPhrase(), "\"\n";   // the standard phrase, or ''
}
echo $response->withStatus(200, 'Fine')->getReasonPhrase(), "\n";
echo $response->getStatusCode(), "\n";                                   // the original is unchanged
