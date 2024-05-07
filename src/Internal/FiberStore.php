<?php
namespace phasync\Internal;

use Closure;
use Countable;
use Fiber;
use IteratorAggregate;
use LogicException;
use phasync\Legacy\Loop;
use RuntimeException;
use Traversable;

/**
 * Stores Fiber instances until this object is garbage collected
 * or the Fibers are explicitly released.
 *
 * @package phasync\Util
 */
final class FiberStore implements Countable, IteratorAggregate {

    private static array $pool = [];

    public static function create(mixed $data, Closure $onGarbageCollect=null): FiberStore {
        if (self::$pool !== []) {
            $fs = \array_pop(self::$pool);
            $fs->data = $data;
            $fs->onGarbageCollect = $onGarbageCollect;
            return $fs;
        } else {
            return new FiberStore($data, $onGarbageCollect);
        }
    }

    private mixed $data;
    private ?Closure $onGarbageCollect;

    /**
     * Holds Fiber instances until 
     * @var array<int, Fiber>
     */
    private array $storage = [];

    private function __construct(mixed $data, Closure $onGarbageCollect = null) {
        $this->data = $data;
        $this->onGarbageCollect = $onGarbageCollect;
    }

    public function returnToPool(): void {
        if (!empty($this->storage)) {
            throw new RuntimeException("Not empty");
        }
        $this->data = null;
        $this->onGarbageCollect = null;
        self::$pool[] = $this;
    }

    public function __destruct() {
        if ($this->onGarbageCollect) {
            ($this->onGarbageCollect)($this->data, $this);
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