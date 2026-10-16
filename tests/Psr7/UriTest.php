<?php

namespace Tests\Psr7;

use Http\Psr7Test\UriIntegrationTest;
use phasync\Psr\Uri;

/*
 * The php-http/psr7-integration-tests suite (what Nyholm/psr7 and others run) against phasync's own PSR-7 classes.
 */
final class UriTest extends UriIntegrationTest
{
    public function createUri($uri)
    {
        return new Uri($uri);
    }
}
