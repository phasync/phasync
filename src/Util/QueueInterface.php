<?php

namespace phasync\Util;

/**
 * A first-in first-out queue that coroutines may share.
 *
 * ```php
 * $queue = new phasync\Util\Queue();
 * $queue->enqueue('job');
 * if ($queue->tryDequeue($job)) {
 *     echo $job;          // job
 * }
 * ```
 *
 * @template TType
 *
 * @see phasync\Util\Queue
 */
interface QueueInterface extends \Countable, LockInterface
{
    /**
     * Returns true if the queue holds no values.
     */
    public function isEmpty(): bool;

    /**
     * Adds `$value` at the end of the queue.
     *
     * @param TType $value
     */
    public function enqueue(mixed $value): void;

    /**
     * Takes the first value off the queue into `$value`, and returns whether there was one.
     *
     * @param-out TType $value receives the value
     *
     * @return bool false if the queue was empty
     *
     * @see QueueInterface::tryPeek
     */
    public function tryDequeue(mixed &$value): bool;

    /**
     * Copies the first value of the queue into `$value`, without removing it, and returns whether there was one.
     *
     * @param-out TType $value receives the value
     *
     * @return bool false if the queue was empty
     *
     * @see QueueInterface::tryDequeue
     */
    public function tryPeek(mixed &$value): bool;
}
