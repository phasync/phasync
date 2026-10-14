<?php

namespace phasync;

/**
 * The lifetime of a `phasync::run()` call: the coroutines started inside it, which it waits for, cancels and rethrows.
 *
 * Every `run()` creates a frame, and `go()` starts its coroutine in the frame of the caller. `run()` returns once every coroutine of its frame has ended, those of the frames nested in it included. It then throws what `$fn` threw, else the earliest failure of a coroutine nobody awaited; the coroutines are never cancelled because one failed. A frame is not the identity of the work: that is the ambient `Scope` (fiber-interop/scope), which a frame neither creates nor changes. One scope may span several frames, and one frame hold coroutines of several scopes.
 *
 * `phasync::frame()` returns the frame of the running coroutine. A service (`phasync::service()`) has none.
 *
 * ```php
 * phasync::run(function () {
 *     phasync::frame()->setTimeout(2.0);   // the whole run, its coroutines included
 *     phasync::go(fn () => fetch('a'));
 *     phasync::go(fn () => fetch('b'));
 * });   // throws phasync\TimeoutException after 2 seconds
 * ```
 *
 * @see phasync::run
 * @see phasync::frame
 * @see phasync::cancel  cancels one coroutine
 *
 * @topic coroutines
 *
 * @example examples/Frame/1-cancel-a-job-and-what-it-started.php Cancel a job and everything it started
 */
final class Frame
{
    /** @internal the frame of the run() this one was entered from; null for the outermost run() and the services' */
    public ?Frame $parent = null;

    /**
     * @internal the coroutines of the frame: the coroutine itself while it is the only one, a set once another joins
     *
     * @var \Fiber|\WeakMap<\Fiber, true>|null
     */
    public \Fiber|\WeakMap|null $fibers = null;

    /** @internal how many coroutines are members; the coroutine that runs a nested run() inline is one of them */
    public int $live = 0;

    /**
     * @internal the frames of the nested run() calls in progress
     *
     * @var array<int, Frame>
     */
    public array $inner = [];

    /** @internal the cancellation every wait of a coroutine in the frame throws, once cancelled */
    public ?\Throwable $cancellation = null;

    /** @internal the deadlock that failed the outermost run() */
    public ?\Throwable $fatal = null;

    /** @internal the TimeoutException run() throws when the deadline cancelled the frame */
    public ?TimeoutException $timeout = null;

    /** @internal the deadline (microtime) set by setTimeout() */
    public ?float $deadline = null;

    /** @internal the run() has returned */
    public bool $ended = false;

    /**
     * @internal the failures nobody awaited, each with the order it happened in
     *
     * @var list<array{0: int, 1: \Throwable}>
     */
    public array $failures = [];

    /** @internal */
    public ?\Closure $exceptionHandler = null;

    /**
     * @internal the failures waiting for the exception handler, which runs from the loop
     *
     * @var list<\Throwable>
     */
    public array $handlerQueue = [];

    /** @internal frames are created by phasync::run() */
    public function __construct(?Frame $parent)
    {
        $this->parent = $parent;
    }

    /**
     * Cancels every coroutine of the frame, and of the frames nested in it, with a CancelledException.
     *
     * As {@see phasync::cancel()} does for one coroutine: the cancellation is sticky, so every wait of a coroutine in the frame throws it, until the coroutine ends or the nested `run()` it is in returns. The deepest in the tree of coroutines are cancelled first. A coroutine that is running, and not waiting, meets it at its next wait; the calling coroutine, if it is in the frame, gets it thrown at once. A coroutine that ends with the cancellation is no failure, so `run()` throws it only when `$fn` does.
     *
     * ```php
     * phasync::run(function () {
     *     phasync::go(fn () => phasync::sleep(10));
     *     phasync::go(fn () => phasync::sleep(10));
     *     phasync::frame()->cancel();   // throws here: the main coroutine is in the frame
     * });
     * ```
     *
     * @param string|\Stringable|CancelledException $message the message of the CancelledException, or the exception itself
     *
     * @throws CancelledException when the calling coroutine is in the frame
     * @throws \LogicException    when the frame's run() has returned
     *
     * @see phasync::cancel
     * @see Frame::setTimeout
     */
    public function cancel(string|\Stringable|CancelledException $message = 'Operation cancelled'): void
    {
        if ($this->ended) {
            throw new \LogicException('The run() of this frame has returned');
        }
        \phasync::getLoop()->cancelFrame($this, $message instanceof CancelledException ? $message : new CancelledException((string) $message));
    }

    /**
     * Cancels the frame when `$seconds` have passed, and makes its `run()` throw a TimeoutException instead of the cancellation.
     *
     * The deadline counts from the call. Calling it again replaces the deadline. The coroutines of the frame see a CancelledException whose previous exception is the TimeoutException, as for {@see Frame::cancel()}. A `run()` whose `$fn` catches the cancellation and returns, returns normally.
     *
     * ```php
     * phasync::run(function () {
     *     phasync::frame()->setTimeout(0.5);
     *     phasync::sleep(10);
     * });   // throws phasync\TimeoutException after 0.5 seconds
     * ```
     *
     * @param float $seconds from now
     *
     * @throws \LogicException when the frame's run() has returned
     *
     * @see Frame::cancel
     * @see phasync::await  a timeout on one wait
     */
    public function setTimeout(float $seconds): void
    {
        if ($this->ended) {
            throw new \LogicException('The run() of this frame has returned');
        }
        \phasync::getLoop()->setDeadline($this, \microtime(true) + $seconds);
    }

    /**
     * Sends the failures of the frame's coroutines that nobody awaited to `$handler`, instead of having `run()` throw them.
     *
     * A failure is known once the failed coroutine's `Fiber` is released, and at the latest when the `run()` ends. The handler runs in the event loop, outside any coroutine, so it must not wait. What it throws, `run()` throws, as a failure of the frame. What `$fn` throws is not passed to it: `run()` throws that. `null` removes the handler.
     *
     * ```php
     * phasync::run(function () use ($logger) {
     *     phasync::frame()->setExceptionHandler(fn (Throwable $e) => $logger->error($e->getMessage()));
     *     phasync::go(fn () => throw new RuntimeException('lost'));   // logged; run() goes on
     * });
     * ```
     *
     * @param \Closure(\Throwable):void|null $handler
     *
     * @see phasync::run
     */
    public function setExceptionHandler(?\Closure $handler): void
    {
        $this->exceptionHandler = $handler;
    }
}
