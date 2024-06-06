<?php

namespace phasync\Internal;

use phasync\Legacy\Loop;

/**
 * This class stores callbacks, and ensures that the callbacks are invoked if the object
 * is garbage collected. Garbage collection is considered accidental, and ideally the
 * application should retrieve the closures and invoke them and return the ClosureStore
 * object to the object pool.
 *
 * @internal
 */
final class ClosureStore_niu implements \Countable, \IteratorAggregate
{
    private static array $pool = [];

    public static function create(mixed $data, ?\Closure $onGarbageCollect = null): self
    {
        if ([] !== self::$pool) {
            $cs                   = \array_pop(self::$pool);
            $cs->data             = $data;
            $cs->onGarbageCollect = $onGarbageCollect;

            return $cs;
        }

        return new self($onGarbageCollect);
    }

    private mixed $data = null;
    private ?\Closure $onGarbageCollect;

    /**
     * Holds Fiber instances until
     *
     * @var Fiber[]
     */
    private array $storage = [];

    private function __construct(mixed $data, ?\Closure $onGarbageCollect = null)
    {
        $this->data             = $data;
        $this->onGarbageCollect = $onGarbageCollect;
    }

    public function returnToPool(): void
    {
        if (!empty($this->storage)) {
            throw new \RuntimeException('Not empty');
        }
        $this->data             = null;
        $this->onGarbageCollect = null;
        self::$pool[]           = $this;
    }

    public function __destruct()
    {
        if ($this->onGarbageCollect) {
            ($this->onGarbageCollect)($this->data, $this);

            return;
        }
        if (!empty($this->storage)) {
            Loop::handleException(new \LogicException('ClosureStore garbage collected and closures were lost'));
        }
    }

    public function count(): int
    {
        return \count($this->storage);
    }

    public function getIterator(): \Traversable
    {
        foreach ($this->storage as $key => $value) {
            unset($this->storage[$key]);
            yield $key => $value;
        }
    }

    public function add(\Closure $closure): void
    {
        $objectId = \spl_object_id($closure);
        if (isset($this->storage[$objectId])) {
            throw new \LogicException('The fiber is already stored here.');
        }
        $this->storage[$objectId] = $closure;
    }

    public function remove(\Closure $closure): void
    {
        $objectId = \spl_object_id($closure);
        if (!isset($this->storage[$objectId])) {
            throw new \LogicException('The fiber is not stored here.');
        }
        unset($this->storage[$objectId]);
    }

    public function contains(\Closure $closure): bool
    {
        $objectId = \spl_object_id($closure);

        return isset($this->storage[$objectId]);
    }
}
