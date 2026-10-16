<?php

namespace Tests\Psr7;

use Http\Psr7Test\ResponseIntegrationTest;
use phasync\Psr\Response;

/*
 * The php-http/psr7-integration-tests suite (what Nyholm/psr7 and others run) against phasync's own PSR-7 classes.
 */
final class ResponseTest extends ResponseIntegrationTest
{
    public function createSubject()
    {
        return new Response();
    }
}
