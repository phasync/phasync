<?php

namespace phasync;

/**
 * Coroutines wait, and nothing can ever wake them: the outermost `run()` throws this.
 *
 * The loop finds this exactly, never by time: when no coroutine can run, no callback is queued, and no timer, timeout, `idle()` waiter, watched stream or signal is left, every coroutine still waiting waits forever. The run then fails as for any other failure, see {@see phasync::run()}: its coroutines are cancelled with a CancelledException whose previous exception is this one, their `finally` blocks run, and the outermost `run()` throws it. The message lists every waiting coroutine with the call it waits in and where that call is.
 *
 * A coroutine waiting for a stream, a pipe or a socket is never part of a stall, since what it waits for can come from another process; so two coroutines each reading their end of a `stream_socket_pair()` wait forever. Nor is a stall of some coroutines while others make progress.
 *
 * ```php
 * try {
 *     phasync::run(function () {
 *         $done = new phasync\Util\WaitGroup();
 *         $done->add();
 *         $done->await();     // nobody calls done()
 *     });
 * } catch (phasync\DeadlockException $e) {
 *     echo $e->getMessage(), "\n";
 * }
 * ```
 *
 * @see phasync::run
 * @see phasync\TimeoutException  a wait that gives up by itself
 *
 * @topic the-event-loop
 *
 * @example examples/DeadlockException/1-nobody-calls-done.php A WaitGroup nobody calls done() on
 */
final class DeadlockException extends \RuntimeException
{
}
