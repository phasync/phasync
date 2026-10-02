<?php

namespace phasync\Util;

/**
 * A list of listeners that are called when the event is triggered.
 *
 * Listeners are called in the order they were added, in the coroutine that triggers the event, one at a time: a slow listener delays the trigger. A listener that throws stops the trigger: the exception reaches the caller of `trigger()` and the listeners after it are not called.
 *
 * ```php
 * $onMessage = new phasync\Util\Event();
 * $onMessage->listen(function (string $data, bool $binary) {
 *     echo "got $data\n";
 * });
 * $onMessage->once(fn () => print("first message only\n"));
 *
 * $onMessage->trigger('hello', false);   // got hello, then: first message only
 * $onMessage->trigger('again', false);   // got again
 * ```
 *
 * @see phasync\Util\Event::listen
 * @see phasync\Util\Event::trigger
 */
final class Event
{
    /** @var list<\Closure> */
    private array $listeners = [];

    /** @var list<\Closure> */
    private array $onceListeners = [];

    /**
     * Calls the listeners with `$args`, then the `once()` listeners, which are forgotten after that.
     *
     * @param mixed ...$args passed to every listener
     *
     * @throws \Throwable what a listener threw
     *
     * @see Event::listen
     * @see Event::once
     */
    public function trigger(mixed ...$args): void
    {
        foreach ($this->listeners as $listener) {
            $listener(...$args);
        }
        $once                = $this->onceListeners;
        $this->onceListeners = [];
        foreach ($once as $listener) {
            $listener(...$args);
        }
    }

    /**
     * Calls `$listeners` every time the event triggers.
     *
     * @see Event::once
     * @see Event::off
     */
    public function listen(\Closure ...$listeners): void
    {
        \array_push($this->listeners, ...$listeners);
    }

    /**
     * Calls `$listeners` the next time the event triggers, and not after.
     *
     * @see Event::listen
     * @see Event::off
     */
    public function once(\Closure ...$listeners): void
    {
        \array_push($this->onceListeners, ...$listeners);
    }

    /**
     * Removes `$listeners`, whether they were added with `listen()` or `once()`.
     *
     * @param \Closure ...$listeners the same closure objects that were added
     *
     * @see Event::listen
     */
    public function off(\Closure ...$listeners): void
    {
        $this->listeners     = \array_values(\array_filter($this->listeners, static fn ($l) => !\in_array($l, $listeners, true)));
        $this->onceListeners = \array_values(\array_filter($this->onceListeners, static fn ($l) => !\in_array($l, $listeners, true)));
    }

    /**
     * Returns true if any listener is registered, with `listen()` or `once()`.
     *
     * @see Event::listen
     */
    public function hasListeners(): bool
    {
        return $this->listeners || $this->onceListeners;
    }
}
