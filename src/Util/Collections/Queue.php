<?php

namespace phasync\Util\Collections;

use phasync\Interfaces\QueueInterface;
use phasync\TimeoutException;
use phasync\Util\LockTrait;

/**
 * Implementation of a concurrency safe queue.
 *
 * @template TType
 */
class Queue implements QueueInterface
{
    use LockTrait;

    private \SplQueue $queue;

    public function __construct()
    {
        $this->queue = new \SplQueue();
    }

    public function isEmpty(): bool
    {
        return $this->queue->isEmpty();
    }

    public function enqueue(mixed $value): void
    {
        $this->lock(function () use ($value) {
            $this->queue->enqueue($value);
        });
    }

    /**
     * @param ?TType $value
     *
     * @throws TimeoutException
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
     * @param ?TType $value
     *
     * @throws TimeoutException
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

    public function count(): int
    {
        return $this->queue->count();
    }
}
