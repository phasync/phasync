<?php

namespace phasync\Util;

/**
 * A list of listeners that are called when the event is triggered.
 *
 *     $onMessage = new Event();
 *     $onMessage->listen(function (string $data, bool $binary) { ... });
 *     $onMessage->trigger('hello', false);
 *
 * Listeners are called in the order they were added, in the coroutine that triggers the event,
 * and one at a time: a slow listener delays the trigger. An exception from a listener
 * propagates to the caller of trigger(), and the listeners after it are not called.
 */
final class Event
{
    /** @var list<\Closure> */
    private array $listeners = [];

    /** @var list<\Closure> */
    private array $onceListeners = [];

    /** Call the listeners with the arguments, then the once() listeners, which are then forgotten. */
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

    /** Call the listeners every time the event triggers. */
    public function listen(\Closure ...$listeners): void
    {
        \array_push($this->listeners, ...$listeners);
    }

    /** Call the listeners the next time the event triggers, and not after. */
    public function once(\Closure ...$listeners): void
    {
        \array_push($this->onceListeners, ...$listeners);
    }

    /** Remove the listeners, added with listen() or once(). */
    public function off(\Closure ...$listeners): void
    {
        $this->listeners     = \array_values(\array_filter($this->listeners, static fn ($l) => !\in_array($l, $listeners, true)));
        $this->onceListeners = \array_values(\array_filter($this->onceListeners, static fn ($l) => !\in_array($l, $listeners, true)));
    }

    public function hasListeners(): bool
    {
        return $this->listeners || $this->onceListeners;
    }
}
