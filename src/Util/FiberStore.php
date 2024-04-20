<?php
namespace phasync\Util;

use Closure;
use Countable;
use Fiber;
use IteratorAggregate;
use LogicException;
use phasync\Loop;
use Traversable;

/**
 * Stores Fiber instances until this object is garbage collected
 * or the Fibers are explicitly released.
 *
 * @package phasync\Util
 */
final class FiberStore implements Countable, IteratorAggregate {

    private readonly ?Closure $onGarbageCollect;

    /**
     * Holds Fiber instances until 
     * @var array<int, Fiber>
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
            Loop::handleException(new LogicException("FiberStore garbage collected and fibers were lost"));
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

    public function add(Fiber $fiber): void {
        $objectId = \spl_object_id($fiber);
        if (isset($this->storage[$objectId])) {
            throw new LogicException("The fiber is already stored here.");
        }
        $this->storage[$objectId] = $fiber;
    }

    public function remove(Fiber $fiber): void {
        $objectId = \spl_object_id($fiber);
        if (!isset($this->storage[$objectId])) {
            throw new LogicException("The fiber is not stored here.");
        }
        unset($this->storage[$objectId]);
    }

    public function contains(Fiber $fiber): bool {
        $objectId = \spl_object_id($fiber);
        return isset($this->storage[$objectId]);
    }
}