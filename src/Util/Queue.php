<?php

namespace phasync\Util;

/**
 * A first-in first-out queue whose operations take a lock, so coroutines may share it.
 *
 * Nothing here waits for a value: `tryDequeue()` returns false when the queue is empty. To wait for values, use a channel.
 *
 * ```php
 * $queue = new phasync\Util\Queue();
 * $queue->enqueue('a');
 * $queue->enqueue('b');
 *
 * $queue->tryDequeue($value);   // $value is 'a'
 * echo count($queue), "\n";     // 1
 * ```
 *
 * @template TType
 *
 * @see phasync::channel  to wait for values
 * @see phasync\Util\LockInterface
 */
class Queue implements QueueInterface
{
    use LockTrait;

    private \SplQueue $queue;

    /**
     * Creates an empty queue.
     */
    public function __construct()
    {
        $this->queue = new \SplQueue();
    }

    /**
     * Returns true if the queue holds no values.
     */
    public function isEmpty(): bool
    {
        return $this->queue->isEmpty();
    }

    /**
     * Adds `$value` at the end of the queue.
     *
     * @param TType $value
     */
    public function enqueue(mixed $value): void
    {
        $this->lock(function () use ($value) {
            $this->queue->enqueue($value);
        });
    }

    /**
     * Takes the first value off the queue into `$value`, and returns whether there was one.
     *
     * When the queue is empty, `$value` is set to null and the result is false.
     *
     * @param ?TType $value receives the value
     *
     * @return bool false if the queue was empty
     *
     * @see Queue::tryPeek
     */
    public function tryDequeue(mixed &$value): bool
    {
        return $this->lock(function () use (&$value) {
            if ($this->queue->isEmpty()) {
                $value = null;

                return false;
            }
            $value = $this->queue->dequeue();

            return true;
        });
    }

    /**
     * Copies the first value of the queue into `$value`, without removing it, and returns whether there was one.
     *
     * @param ?TType $value receives the value
     *
     * @return bool false if the queue was empty
     *
     * @see Queue::tryDequeue
     */
    public function tryPeek(mixed &$value): bool
    {
        return $this->lock(function () use (&$value) {
            if ($this->queue->isEmpty()) {
                $value = null;

                return false;
            }
            $value = $this->queue->bottom();

            return true;
        });
    }

    /**
     * Returns the number of values in the queue.
     */
    public function count(): int
    {
        return $this->queue->count();
    }
}
