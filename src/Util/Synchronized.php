<?php

namespace phasync\Util;

use phasync\TimeoutException;

/**
 * Placeholder class for supporting thread safe synchronization. The current
 * implementation is not thread safe, but is safe from within fibers.
 */
final class Synchronized
{
    private static array $locks = [];

    /**
     * Run a function ensuring that the function will not be invoked by other
     * coroutines at the same time.
     *
     * @throws TimeoutException
     * @throws \Throwable
     */
    public static function run(object|string $token, \Closure $closure): mixed
    {
        if (\is_object($token)) {
            $token = \spl_object_hash($token);
        }
        while (isset(self::$locks[$token])) {
            \phasync::awaitFlag(self::$locks[$token]);
        }
        try {
            self::$locks[$token] = new \stdClass();

            return $closure();
        } finally {
            \phasync::raiseFlag(self::$locks[$token]);
            unset(self::$locks[$token]);
        }
    }
}
