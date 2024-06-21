<?php

namespace phasync\Psr;

use phasync;
use phasync\Legacy\Loop;
use Psr\Http\Message\StreamInterface;

/**
 * Represents a file backed temporary buffer that can be accessed
 * asynchronously.
 */
final class TempFileStream extends ResourceStream implements StreamInterface
{

    public static function fromString(string $contents): TempFileStream
    {
        $stream = new TempFileStream();
        $stream->write($contents);
        $stream->rewind();

        return $stream;
    }

    public function __construct(string $mode = 'r+')
    {
        $fp = \tmpfile();
        parent::__construct($fp, $mode);
    }
}
