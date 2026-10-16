<?php

namespace Tests\Psr7;

use Http\Psr7Test\StreamIntegrationTest;
use phasync\Psr\ResourceStream;

/*
 * The php-http/psr7-integration-tests suite (what Nyholm/psr7 and others run) against phasync's own PSR-7 classes.
 */
final class StreamTest extends StreamIntegrationTest
{
    public function createStream($data)
    {
        \stream_set_blocking($data, false);

        return new ResourceStream($data);
    }
}
