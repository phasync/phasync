<?php

namespace phasync;

/**
 * Thrown into the coroutines of a run by `phasync::shutdown()`: the program is stopping. It is a
 * CancelledException, so it behaves like one: the coroutine meets it at its wait, its `finally`
 * blocks run, and cleanup that must wait belongs in `phasync::finally()`. Code that only needs to
 * stop catches nothing, or CancelledException; code that must tell someone why catches this.
 *
 * @see phasync::shutdown
 *
 * @topic cancellation-and-cleanup
 *
 * @example examples/ShutdownException/1-why-it-stopped.php Tell the client why the coroutine stops
 */
class ShutdownException extends CancelledException
{
}
