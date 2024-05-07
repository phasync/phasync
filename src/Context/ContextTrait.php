<?php
namespace phasync\Context;

use Fiber;
use LogicException;
use SplObjectStorage;
use Throwable;
use WeakMap;

/**
 * Implements the required functionality of a ContextInterface
 * object.
 */
trait ContextTrait {
    private ?Throwable $contextException = null;
    private ?Fiber $rootFiber = null;
    private ?WeakMap $fibers = null;
    private array $dataKeyed = [];
    private SplObjectStorage $dataObjectKeys;

    public function setContextException(Throwable $exception): void {
        if ($this->contextException !== null) {
            throw new LogicException("Context Exception already set");
        }
        $this->contextException = $exception;
    }

    public function getContextException(): ?Throwable {
        return $this->contextException;
    }

    public function setRootFiber(Fiber $fiber): void {
        $this->rootFiber = $fiber;
    }

    

    public function getRootFiber(): Fiber {
        if ($this->rootFiber === null) {
            throw new LogicException("Root Fiber not set");
        }
        return $this->rootFiber;
    }

    public function getFibers(): WeakMap {
        if ($this->fibers === null) {
            $this->fibers = new WeakMap();
        }
        return $this->fibers;
    }

    public function offsetExists(mixed $offset): bool {
        if (is_object($offset)) {
            $this->enableObjectKeys();
            return isset($this->dataObjectKeys[$offset]);
        }
        return isset($this->dataKeyed[$offset]) || \array_key_exists($offset, $this->dataKeyed);
    }

    public function offsetGet(mixed $offset): mixed {
        if (is_object($offset)) {
            $this->enableObjectKeys();
            return $this->dataObjectKeys[$offset];
        }
        return $this->dataKeyed[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        if (is_object($offset)) {
            $this->enableObjectKeys();
            $this->dataObjectKeys[$offset] = $value;
        } else {
            $this->dataKeyed[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void {
        if (is_object($offset)) {
            $this->enableObjectKeys();
            unset($this->dataObjectKeys[$offset]);
        } else {
            unset($this->dataKeyed[$offset]);
        }
    }

    private function enableObjectKeys(): void {
        if ($this->dataObjectKeys === null) {
            $this->dataObjectKeys = new SplObjectStorage();
        }
    }
}