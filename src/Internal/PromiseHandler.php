<?php

namespace phasync\Internal;

use Closure;

/**
 * How `phasync::await()` waits for a promise from another library. The default handler
 * recognizes any object with a `then()` method; an integration for a library whose promises
 * differ (ReactPHP, for one) replaces it with {@see PromiseHandler::set()}.
 *
 * @internal
 */
final class PromiseHandler
{
    /**
     * @var null|Closure{mixed, ?Closure, ?Closure, bool}
     */
    private static ?\Closure $handler = null;

    /**
     * Attaches `$onFulfilled` and/or `$onRejected` to the promise and returns true, or returns
     * false when `$promiseLike` is not a promise.
     */
    public static function handle(mixed $promiseLike, ?\Closure $onFulfilled = null, ?\Closure $onRejected = null): bool
    {
        return (self::$handler ?? self::duckTyped(...))($promiseLike, $onFulfilled, $onRejected);
    }

    /**
     * Replaces the handler. It takes the same arguments and returns the same as {@see PromiseHandler::handle()}.
     * To keep the existing integrations, wrap {@see PromiseHandler::get()}.
     *
     * @param Closure{mixed, ?Closure, ?Closure, bool} $handler
     */
    public static function set(\Closure $handler): void
    {
        self::$handler = $handler;
    }

    public static function get(): \Closure
    {
        return self::$handler ?? self::duckTyped(...);
    }

    private static function duckTyped(mixed $promiseLike, ?\Closure $onFulfilled = null, ?\Closure $onRejected = null): bool
    {
        if (!\is_object($promiseLike) || !\method_exists($promiseLike, 'then')) {
            return false;
        }
        $rm = new \ReflectionMethod($promiseLike, 'then');
        if ($rm->isStatic()) {
            return false;
        }
        $onRejectedHandled = false;
        foreach ($rm->getParameters() as $index => $rp) {
            if ($rp->hasType()) {
                $rt = $rp->getType();
                if ($rt instanceof \ReflectionNamedType) {
                    if (
                        'mixed' !== $rt->getName()
                        && 'callable' !== $rt->getName()
                        && \Closure::class !== $rt->getName()
                    ) {
                        return false;
                    }
                }
                // mixed type apparently
            }
            if ($rp->isVariadic()) {
                // Can handle many arguments of this type
                $onRejectedHandled = true;
                break;
            }
            if (1 === $index) {
                $onRejectedHandled = true;
                // Can handle at least two arguments of this type
                break;
            }
        }

        if (null !== $onRejected && !$onRejectedHandled) {
            // The promise does not handle $onRejected in the `then`
            // method, so see if we find a `catch` method.
            if (\method_exists($promiseLike, 'catch')) {
                if (null !== $onFulfilled) {
                    $promiseLike->then($onFulfilled);
                }
                $promiseLike->catch($onRejected);

                return true;
            }

            return false;
        }

        if (null !== $onFulfilled && null !== $onRejected) {
            $promiseLike->then($onFulfilled, $onRejected);
        } elseif (null !== $onFulfilled) {
            $promiseLike->then($onFulfilled);
        } elseif (null !== $onRejected) {
            $promiseLike->then(null, $onRejected);
        }

        return true;
    }
}
