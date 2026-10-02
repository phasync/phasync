<?php

namespace phasync\Util;

use phasync\TimeoutException;

/**
 * A pool of interchangeable resources, of which only so many may exist at once.
 *
 * For database connections, sockets to a service, workers. A coroutine borrows one, uses it alone, and releases it; while all are out, the next borrower waits for one.
 *
 * - Instances are made by `$create` when one is needed and none is free, up to `$size`; they are reused after that.
 * - Borrowers waiting are served in the order they came: a released instance goes to the one that waited longest.
 * - An instance that broke (a lost database connection) is given back with `discard()` instead: the pool forgets it, and makes a new one when needed.
 * - An instance dropped by its borrower without `release()` or `discard()` is noticed when it is destroyed: the pool warns (`E_USER_WARNING`) and makes a new one in its place. A borrower waiting meanwhile finds out within a second.
 *
 * ```php
 * $db = new phasync\Util\Pool(fn () => new PDO($dsn, $user, $password), 10);
 *
 * $rows = $db->use(fn (PDO $pdo) => $pdo->query('SELECT ...')->fetchAll());
 *
 * $pdo = $db->borrow();          // or by hand
 * try {
 *     // ...
 * } finally {
 *     $db->release($pdo);
 * }
 * ```
 *
 * @template T of object
 *
 * @see phasync\Util\Synchronized
 * @see phasync\Util\RateLimiter
 */
final class Pool implements \Countable
{
    /** @var list<T> instances ready to lend, the most recently used last */
    private array $idle = [];

    /** @var \WeakMap<T, true> instances lent out */
    private \WeakMap $out;

    /** Instances lent out, lost ones included until noticed. */
    private int $lent = 0;

    /** Instances being made by $create. */
    private int $creating = 0;

    /** @var \SplQueue<\stdClass> borrowers waiting: ->instance is set when one is handed over */
    private \SplQueue $waiting;

    /**
     * Creates an empty pool; instances are made when they are needed.
     *
     * @param \Closure(): T $create makes an instance, in the borrowing coroutine, so it may wait
     * @param int           $size   the most instances in existence at once
     *
     * @throws \InvalidArgumentException if `$size` is below 1
     */
    public function __construct(private readonly \Closure $create, private readonly int $size)
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('A pool holds at least one instance');
        }
        $this->out     = new \WeakMap();
        $this->waiting = new \SplQueue();
    }

    /**
     * Lends an instance to the caller until it calls `release()` or `discard()`, and waits while all are out.
     *
     * @param float $timeout seconds to wait for an instance at most
     *
     * @throws TimeoutException if no instance became free in time
     * @throws \Throwable       what `$create` threw
     *
     * @return T an instance that no other coroutine has
     *
     * @see Pool::use
     * @see Pool::release
     */
    public function borrow(float $timeout = \PHP_FLOAT_MAX): object
    {
        $deadline = \microtime(true) + $timeout;
        $this->noticeLost();
        if ($this->waiting->isEmpty()) {
            if ($this->idle) {
                return $this->lend(\array_pop($this->idle));
            }
            if ($this->total() < $this->size) {
                return $this->make();
            }
        }
        $ticket           = new \stdClass();
        $ticket->instance = null;
        $ticket->gone     = false;
        $this->waiting->enqueue($ticket);
        try {
            while (null === $ticket->instance) {
                $remaining = $deadline - \microtime(true);
                if ($remaining <= 0) {
                    throw new TimeoutException('No instance became free within ' . $timeout . ' s');
                }
                try {
                    // A second at most: a lost instance frees its place without telling anyone
                    \phasync::awaitFlag($ticket, \min($remaining, 1.0));
                } catch (TimeoutException) {
                }
                if (null !== $ticket->instance) {
                    break;
                }
                $this->noticeLost();
                if ($this->total() < $this->size && $this->first() === $ticket) {
                    $ticket->gone = true;
                    $this->waiting->dequeue();

                    return $this->make();
                }
            }

            return $ticket->instance;
        } catch (\Throwable $e) {
            // Timed out or cancelled: leave the line; an instance handed over meanwhile goes on
            $ticket->gone = true;
            if (null !== $ticket->instance) {
                $this->release($ticket->instance);
            }
            $this->wakeFirst();
            throw $e;
        }
    }

    /**
     * Gives a borrowed instance back, for the next borrower.
     *
     * @param T $instance an instance from `borrow()`
     *
     * @throws \LogicException if the pool did not lend it
     *
     * @see Pool::discard
     */
    public function release(object $instance): void
    {
        $this->giveBack($instance);
        while (!$this->waiting->isEmpty()) {
            $ticket = $this->waiting->dequeue();
            if (!$ticket->gone) {
                $ticket->instance = $this->lend($instance);
                \phasync::raiseFlag($ticket);

                return;
            }
        }
        $this->idle[] = $instance;
    }

    /**
     * Gives a borrowed instance back as broken: the pool forgets it, and makes a new one when needed.
     *
     * @param T $instance an instance from `borrow()`
     *
     * @throws \LogicException if the pool did not lend it
     *
     * @see Pool::release
     */
    public function discard(object $instance): void
    {
        $this->giveBack($instance);
        $this->wakeFirst();
    }

    /**
     * Runs `$fn` with an instance of its own, and releases the instance afterwards, also when `$fn` throws.
     *
     * Call `discard()` inside `$fn` if the instance broke.
     *
     * @template R
     *
     * @param \Closure(T): R $fn      what to run with the instance
     * @param float          $timeout seconds to wait for an instance at most
     *
     * @throws TimeoutException if no instance became free in time
     * @throws \Throwable       what `$create` or `$fn` threw
     *
     * @return R what `$fn` returned
     *
     * @see Pool::borrow
     */
    public function use(\Closure $fn, float $timeout = \PHP_FLOAT_MAX): mixed
    {
        $instance = $this->borrow($timeout);
        try {
            return $fn($instance);
        } finally {
            if (isset($this->out[$instance])) {
                $this->release($instance);
            }
        }
    }

    /**
     * Returns the number of instances in existence: lent out, being made, and idle.
     */
    public function count(): int
    {
        $this->noticeLost();

        return $this->total();
    }

    /** @param T $instance */
    private function giveBack(object $instance): void
    {
        if (!isset($this->out[$instance])) {
            throw new \LogicException(\get_debug_type($instance) . ' is not lent out by this pool');
        }
        unset($this->out[$instance]);
        --$this->lent;
    }

    /**
     * @param T $instance
     *
     * @return T
     */
    private function lend(object $instance): object
    {
        $this->out[$instance] = true;
        ++$this->lent;

        return $instance;
    }

    /** @return T */
    private function make(): object
    {
        ++$this->creating;
        try {
            $instance = ($this->create)();
        } catch (\Throwable $e) {
            --$this->creating;
            $this->wakeFirst(); // its place is free again
            throw $e;
        }
        --$this->creating;

        return $this->lend($instance);
    }

    private function total(): int
    {
        return \count($this->idle) + $this->lent + $this->creating;
    }

    /** Lent instances destroyed without release() or discard(): warn, and free their places. */
    private function noticeLost(): void
    {
        $lost = $this->lent - \count($this->out);
        if ($lost > 0) {
            $this->lent -= $lost;
            \trigger_error(\sprintf('%d instance%s borrowed from a Pool %s destroyed without release() or discard(); a new one is made in %s place', $lost, 1 === $lost ? '' : 's', 1 === $lost ? 'was' : 'were', 1 === $lost ? 'its' : 'their'), \E_USER_WARNING);
            $this->wakeFirst();
        }
    }

    /** The first borrower in line, or null. */
    private function first(): ?\stdClass
    {
        while (!$this->waiting->isEmpty() && $this->waiting->bottom()->gone) {
            $this->waiting->dequeue();
        }

        return $this->waiting->isEmpty() ? null : $this->waiting->bottom();
    }

    /** A place came free: the first in line makes an instance for itself. */
    private function wakeFirst(): void
    {
        if (null !== ($first = $this->first())) {
            \phasync::raiseFlag($first);
        }
    }
}
