<?php

namespace phasync;

/**
 * A stream wait failed: the value was no stream, the stream was closed while waited for, or polling failed.
 *
 * ```php
 * phasync::run(function () {
 *     try {
 *         phasync::readable('not a stream');
 *     } catch (phasync\IOException $e) {
 *         echo $e->getMessage(), "\n";   // Not a valid stream resource
 *     }
 * });
 * ```
 *
 * @see phasync::readable
 * @see phasync::writable
 *
 * @topic waiting-and-timeouts
 */
class IOException extends \Exception
{
}
