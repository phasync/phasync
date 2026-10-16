<?php

namespace Tests\Psr7;

use Http\Psr7Test\RequestIntegrationTest;
use phasync\Psr\Request;

/*
 * The php-http/psr7-integration-tests suite (what Nyholm/psr7 and others run) against phasync's own PSR-7 classes.
 */
final class RequestTest extends RequestIntegrationTest
{
    public function createSubject()
    {
        return new Request('GET', '/');
    }
}
