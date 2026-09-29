<?php

namespace phasync\Util;

/**
 * Simple coroutine-safe mutex for synchronizing access to shared resources.
 *
 * This implementation is not reentrant - attempting to acquire the same lock
 * again from the coroutine that holds it will throw an exception. For reentrant
 * locking, use LockTrait instead. Other coroutines wait their turn, also those
 * of the same context (the coroutines of one request share it), and get it in
 * the order they asked: the holder hands the lock to the first in line, so a
 * coroutine that asks again, or one that just arrived, goes to the back.
 *
 * Note: Not thread safe if PHP threading is enabled.
 */
final class Synchronized
{
    /** @var array<string, \Fiber|true> the coroutine holding each lock (true outside of any) */
    private static array $holders = [];

    /** @var array<string, array<int, object{fiber: \Fiber|true}>> those in line, first first */
    private static array $lines = [];

    /**
     * Run a function ensuring that the function will not be invoked by other
     * coroutines at the same time.
     *
     * @throws \LogicException if the coroutine that holds the lock asks for it again
     * @throws \Throwable      if the closure throws
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
