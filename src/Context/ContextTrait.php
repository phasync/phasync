<?php

namespace phasync\Context;

use phasync\ContextUsedException;

/**
 * Implements the required functionality of a ContextInterface
 * object.
 */
trait ContextTrait
{
    private ?\Throwable $contextException = null;
    private ?\WeakMap $fibers             = null;
    private array $dataKeyed              = [];
    private \SplObjectStorage $dataObjectKeys;
    private bool $activated = false;

    public function activate(): void
    {
        if ($this->activated) {
            throw new ContextUsedException();
        }
        $this->activated = true;
    }

    public function isActivated(): bool
    {
        return $this->activated;
    }

    public function setContextException(\Throwable $exception): void
    {
        if (null !== $this->contextException) {
            \phasync::logUnhandledException($exception);
        } else {
            $this->contextException = $exception;
        }
    }

    public function getContextException(): ?\Throwable
    {
        return $this->contextException;
    }

    public function getFibers(): \WeakMap
    {
        if (null === $this->fibers) {
            $this->fibers = new \WeakMap();
        }

        return $this->fibers;
    }

    public function offsetExists(mixed $offset): bool
    {
        if (null === $offset) {
            return null;
        }
        if (\is_object($offset)) {
            $this->enableObjectKeys();

            return isset($this->dataObjectKeys[$offset]);
        }

        return isset($this->dataKeyed[$offset]) || \array_key_exists($offset, $this->dataKeyed);
    }

    public function &offsetGet(mixed $offset): mixed
    {
        if (null === $offset) {
            return null;
        }
        if (\is_object($offset)) {
            $this->enableObjectKeys();

            if (!isset($this->dataObjectKeys[$offset])) {
                return null;
            }

            return $this->dataObjectKeys[$offset];
        }

        if (!isset($this->dataKeyed[$offset])) {
            return null;
        }

        return $this->dataKeyed[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (null === $offset) {
            $this->dataKeyed[] = $value;
        } elseif (\is_object($offset)) {
            $this->enableObjectKeys();
            $this->dataObjectKeys[$offset] = $value;
        } else {
            $this->dataKeyed[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        if (\is_object($offset)) {
            $this->enableObjectKeys();
            unset($this->dataObjectKeys[$offset]);
        } else {
            unset($this->dataKeyed[$offset]);
        }
    }

    private function enableObjectKeys(): void
    {
        if (null === $this->dataObjectKeys) {
            $this->dataObjectKeys = new \SplObjectStorage();
        }
    }
}
