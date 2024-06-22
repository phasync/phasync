<?php

namespace phasync\Interfaces;

/**
 * @template TType
 */
interface QueueInterface extends \Countable, LockInterface
{
    /**
     * Is the queue empty?
     */
    public function isEmpty(): bool;

    /**
     * Add an element to the queue
     *
     * @param TType $value
     */
    public function enqueue(mixed $value): void;

    /**
     * Try to dequeue an element from the queue
     *
     * @param-out TType $value
     *
     * @return bool If successfully fetched a value
     */
    public function tryDequeue(mixed &$value): bool;

    /**
     * Try to get the next element from the queue
     *
     * @param-out TType $value
     */
    public function tryPeek(mixed &$value): bool;
}
