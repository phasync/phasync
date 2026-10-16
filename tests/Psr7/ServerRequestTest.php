<?php

namespace Tests\Psr7;

use Http\Psr7Test\ServerRequestIntegrationTest;
use phasync\Psr\ServerRequest;

/*
 * The php-http/psr7-integration-tests suite (what Nyholm/psr7 and others run) against phasync's own PSR-7 classes.
 */
final class ServerRequestTest extends ServerRequestIntegrationTest
{
    public function createSubject()
    {
        return new ServerRequest('GET', '/', null, [], null, $_SERVER);
    }
}
