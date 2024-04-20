<?php
namespace phasync\Util;

use Closure;
use IteratorAggregate;
use LogicException;
use phasync\Loop;
use Traversable;

/**
 * Stores Closure instances until this object is garbage collected
 * or the Closure is explicitly released.
 *
 * @package phasync\Util
 */
final class ClosureStore implements IteratorAggregate {

    private readonly ?Closure $onGarbageCollect;

    /**
     * Holds Fiber instances until 
     * @var Fiber[]
     */
    private array $storage = [];

    public function __construct(Closure $onGarbageCollect = null) {
        $this->onGarbageCollect = $onGarbageCollect;
    }

    public function __destruct() {
        if ($this->onGarbageCollect) {
            ($this->onGarbageCollect)($this);
            return;
        }
        if (!empty($this->storage)) {
            Loop::handleException(new LogicException("ClosureStore garbage collected and closures were lost"));
        }
    }

    public function count(): int {
        return count($this->storage);
    }

    public function getIterator(): Traversable {
        foreach ($this->storage as $key => $value) {
            unset($this->storage[$key]);
            yield $key => $value;
        }
    }

    public function add(Closure $closure): void {
        $objectId = \spl_object_id($closure);
        if (isset($this->storage[$objectId])) {
            throw new LogicException("The fiber is already stored here.");
        }
        $this->storage[$objectId] = $closure;
    }

    public function remove(Closure $closure): void {
        $objectId = \spl_object_id($closure);
        if (!isset($this->storage[$objectId])) {
            throw new LogicException("The fiber is not stored here.");
        }
        unset($this->storage[$objectId]);
    }

    public function contains(Closure $closure): bool {
        $objectId = \spl_object_id($closure);
        return isset($this->storage[$objectId]);
    }
}