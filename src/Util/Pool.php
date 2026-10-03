<?php

namespace phasync\Util;

use phasync\CancelledException;
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
 * - With `$idleTimeout`, idle instances that nobody borrowed for that long are dropped, so memory goes back after a burst; this also happens without traffic, by a background timer that exists only while instances are idle, and that never keeps `phasync::run()` waiting.
 * - With `$dispose`, an instance the pool lets go of (expired, or given back with `discard()`) is passed to it, to close or flush it. `$dispose` must cope with an instance that is already closed or broken, and an exception from it propagates like any coroutine's. It is not called for an instance lost without being given back, which is already destroyed.
 * - `warm()` makes an instance ahead of need, in a coroutine of its own, so that a borrower does not have to wait for `$create`. `idle()` and `lent()` tell how many instances are ready and how many are out.
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

    /** @var list<float> when each idle instance was given back, oldest first */
    private array $idleSince = [];

    /** The coroutine that drops expired idle instances, while there are any. */
    private ?\Fiber $sweeper = null;

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
     * @param \Closure(): T          $create      makes an instance, in the borrowing coroutine, so it may wait
     * @param int                    $size        the most instances in existence at once
     * @param float|null             $idleTimeout seconds an instance may be idle before it is dropped; null keeps idle instances for ever
     * @param \Closure(T): void|null $dispose     called with each instance the pool lets go of, to close or flush it
     *
     * @throws \InvalidArgumentException if `$size` is below 1, or `$idleTimeout` is not above 0
     */
    public function __construct(
        private readonly \Closure $create,
        private readonly int $size,
        private readonly ?float $idleTimeout = null,
        private readonly ?\Closure $dispose = null,
    ) {
        if ($size < 1) {
            throw new \InvalidArgumentException('A pool holds at least one instance');
        }
        if (null !== $idleTimeout && $idleTimeout <= 0) {
            throw new \InvalidArgumentException('An idle timeout is above 0 seconds');
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
        if (null !== $this->idleTimeout) {
            $this->expire(); // between runs no timer has been running
        }
        if ($this->waiting->isEmpty()) {
            if ($this->idle) {
                \array_pop($this->idleSince);

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
        $this->idle[]      = $instance;
        $this->idleSince[] = \microtime(true);
        if (null !== $this->idleTimeout && (null === $this->sweeper || $this->sweeper->isTerminated())) {
            $this->startSweeper();
        }
    }

    /**
     * Gives a borrowed instance back as broken: the pool forgets it, passes it to `$dispose`, and makes a new one when needed.
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
        if (null !== $this->dispose) {
            ($this->dispose)($instance);
        }
    }

    /**
     * Makes one instance in a coroutine of its own and keeps it idle, unless the pool is at its size or a borrower waits.
     *
     * It returns at once: `$create` starts after the caller's next wait, never in the caller's stack, and the place is taken meanwhile. A borrower that needs an instance meanwhile gets this one when it is done. The run does not end before the instance is made.
     *
     * A `$create` that fails does not throw into the caller or warn: the pool forgets the attempt and passes the exception to `$onFailure`, for logging. Without one the failure is dropped, as the next borrower that needs an instance runs `$create` itself and gets the exception where it can handle it (a borrower already waiting does so at once). An exception from `$onFailure` fails the run, like any service's.
     *
     * @param \Closure(\Throwable): void|null $onFailure called with what `$create` threw
     *
     * @throws \LogicException outside a coroutine
     *
     * @see Pool::borrow
     */
    public function warm(?\Closure $onFailure = null): void
    {
        $this->noticeLost();
        if (null !== $this->first() || $this->total() >= $this->size) {
            return;
        }
        ++$this->creating; // the place is taken now, make() gives it up
        \phasync::service(function () use ($onFailure) {
            try {
                $instance = $this->make(true);
            } catch (CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                null === $onFailure || $onFailure($e);

                return;
            }
            $this->release($instance);
        });
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

    /**
     * Returns the number of instances ready to lend, not counting those being made.
     */
    public function idle(): int
    {
        $this->noticeLost();
        if (null !== $this->idleTimeout) {
            $this->expire();
        }

        return \count($this->idle);
    }

    /**
     * Returns the number of instances lent out by `borrow()` and not yet given back.
     */
    public function lent(): int
    {
        $this->noticeLost();

        return $this->lent;
    }

    /**
     * Drops the idle instances that have been idle for too long; returns the seconds until the next
     * would be, or null when none is idle.
     */
    private function expire(): ?float
    {
        $due = \microtime(true) - $this->idleTimeout;
        for ($n = 0, $idle = \count($this->idle); $n < $idle && $this->idleSince[$n] <= $due; ++$n) {
        }
        if ($n > 0) {
            $dropped = \array_splice($this->idle, 0, $n);
            \array_splice($this->idleSince, 0, $n);
            if (null !== $this->dispose) {
                foreach ($dropped as $instance) {
                    ($this->dispose)($instance);
                }
            }
        }

        return $this->idle ? \max(0.0, $this->idleSince[0] + $this->idleTimeout - \microtime(true)) : null;
    }

    /**
     * Starts the coroutine that expires idle instances, until none is idle. It is a background
     * service that holds the pool only weakly: it neither keeps `run()` going nor the pool alive.
     */
    private function startSweeper(): void
    {
        $pool = \WeakReference::create($this);
        \phasync::service(static function () use ($pool) {
            $pool->get()->sweeper = \phasync::getFiber();
            while (null !== ($instance = $pool->get()) && null !== ($wait = $instance->expire())) {
                unset($instance);
                \phasync::sleep($wait);
            }
        }, background: true);
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

    /**
     * @param bool $warming warm() counted the instance already, and has it start after its caller's next wait
     *
     * @return T
     */
    private function make(bool $warming = false): object
    {
        $warming || ++$this->creating;
        try {
            $warming && \phasync::sleep();
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
