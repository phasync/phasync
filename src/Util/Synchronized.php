<?php

namespace phasync\Util;

/**
 * A mutex for coroutines, identified by a string or an object: only one coroutine at a time runs a closure for the same token.
 *
 * Unlike {@see LockTrait}, it is not reentrant: asking again from the coroutine that holds the lock throws. Other coroutines wait their turn, also those of the same context, in the order they asked: the holder hands the lock to the first in line.
 *
 * ```php
 * phasync::run(function () {
 *     foreach ([1, 2, 3] as $i) {
 *         phasync::go(function () use ($i) {
 *             phasync\Util\Synchronized::run('report', function () use ($i) {
 *                 echo "start $i\n";
 *                 phasync::sleep(0.01);
 *                 echo "end $i\n";           // start and end of different jobs never interleave
 *             });
 *         });
 *     }
 * });
 * ```
 *
 * @see phasync\Util\LockTrait
 * @see phasync\Util\Pool
 */
final class Synchronized
{
    /** @var array<string, \Fiber|true> the coroutine holding each lock (true outside of any) */
    private static array $holders = [];

    /** @var array<string, array<int, object{fiber: \Fiber|true}>> those in line, first first */
    private static array $lines = [];

    /**
     * Runs `$closure` once no other coroutine runs a closure for `$token`, and returns what it returns.
     *
     * @param object|string $token   the name of the lock; an object stands for itself
     * @param \Closure      $closure what to run with the lock held
     *
     * @throws \LogicException if the coroutine that holds the lock asks for it again
     * @throws \Throwable      what `$closure` threw
     *
     * @return mixed what `$closure` returned
     */
    public static function run(object|string $token, \Closure $closure): mixed
    {
        if (\is_object($token)) {
            $token = \spl_object_hash($token);
        }

        // The holder is a coroutine, not a context: the coroutines of one context are many
        $current = \Fiber::getCurrent() ?? true;
        if (!isset(self::$holders[$token])) {
            self::$holders[$token] = $current;
        } elseif (self::$holders[$token] === $current) {
            throw new \LogicException('Synchronized::run() is not reentrant; use LockTrait for reentrant locking');
        } else {
            $turn                                              = new \stdClass();
            $turn->fiber                                       = $current;
            self::$lines[$token][\spl_object_id($turn)]        = $turn;
            $given                                             = false;
            try {
                while (self::$holders[$token] !== $current) {
                    \phasync::awaitFlag($turn);
                }
                $given = true;
            } finally {
                if (!$given) {
                    // Gone from the line (cancelled, or the coroutine destroyed): if the lock was
                    // handed to it meanwhile, it goes on to the next
                    if (self::$holders[$token] === $current) {
                        self::release($token);
                    } else {
                        unset(self::$lines[$token][\spl_object_id($turn)]);
                    }
                }
            }
        }
        try {
            return $closure();
        } finally {
            self::release($token);
        }
    }

    /** To the first in line, or free. */
    private static function release(string $token): void
    {
        if (empty(self::$lines[$token])) {
            unset(self::$holders[$token], self::$lines[$token]);

            return;
        }
        $first = \array_key_first(self::$lines[$token]);
        $turn  = self::$lines[$token][$first];
        unset(self::$lines[$token][$first]);
        self::$holders[$token] = $turn->fiber;
        \phasync::raiseFlag($turn);
    }
}
