<?php

namespace phasync\Internal;

/**
 * The flag a coroutine waits on in a poller.
 *
 * @internal
 */
final class PollFlag
{
    /** Why polling failed for the stream, raised with the flag. */
    public ?\Throwable $error = null;
}
