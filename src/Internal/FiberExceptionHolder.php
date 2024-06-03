<?php

namespace phasync\Internal;

use Fiber;
use phasync;

/**
 * This class stores an exception object that is associated with a Fiber in
 * phasync. If the exception holder is garbage collected, the handler is
 * invoked with the exception. Ideally, the exception should be retrieved
 * with the {@see FiberExceptionHolder::get()} method. After this, the
 * FiberExceptionHolder instance should be returned to the object pool
 * for reuse.
 *
 * @internal
 */
final class FiberExceptionHolder
{
    /**
     * @var FiberExceptionHolder[]
     */
    private static array $pool = [];

    public static function create(\Throwable $exception, \Fiber $fiber, ?\Closure $handler): self
    {
        if ([] !== self::$pool) {
            $eh            = \array_pop(self::$pool);
            $eh->handled   = false;
            $eh->exception = $exception;
            $eh->handler   = $handler;
            $eh->fiberRef  = \WeakReference::create($fiber);

            return $eh;
        }

        return new self($exception, $fiber, $handler);
    }

    private bool $handled = false;
    private ?\Throwable $exception;
    private ?\Closure $handler;

    /**
     * Indicates if there still exists a reference to the fiber that
     * threw the exception.
     *
     * @var \WeakReference<\Fiber>
     */
    private \WeakReference $fiberRef;

    private function __construct(\Throwable $exception, \Fiber $fiber, ?\Closure $handler)
    {
        $this->fiberRef  = \WeakReference::create($fiber);
        $this->exception = $exception;
        $this->handler   = $handler;
    }

    /**
     * True if the exception has been retrieved.
     */
    public function isHandled(): bool
    {
        return $this->handled;
    }

    /**
     * True if the fiber that threw the exception no longer exists.
     */
    public function isFiberGone(): bool
    {
        return null === $this->fiberRef->get();
    }

    public function handleException(): void
    {
        if (!$this->handled) {
            $this->handled   = true;
            $exception       = $this->exception;
            $this->exception = null;
            if ($this->handler) {
                ($this->handler)($exception, $this->fiberRef);
            } else {
                throw $exception;
            }
        }
    }

    public function returnToPool(): void
    {
        if (!$this->handled) {
            throw new \LogicException('Not handled, so it should not be returned to the pool');
        }
        $this->exception = null;
        $this->handler   = null;
        self::$pool[]    = $this;
    }

    public function get(): \Throwable
    {
        $this->handled = true;
        $this->handler = null;

        return $this->exception;
    }

    public function __destruct()
    {
        $this->handleException();
    }
}
