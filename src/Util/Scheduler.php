<?php
namespace phasync\Util;

use Fiber;
use LogicException;
use SplMinHeap;
use SplObjectStorage;

/**
 * 
 * @package phasync\Util
 */
class Scheduler extends SplMinHeap {

    /**
     * Holds the scheduled time for a Fiber
     * 
     * @var SplObjectStorage<Fiber,float>
     */
    public SplObjectStorage $times;

    public function __construct() {
        $this->times = new SplObjectStorage();
    }

    public function schedule(float $timestamp, Fiber $fiber) {
        if ($this->times->contains($fiber)) {
            throw new LogicException("The Fiber is already scheduled");
        }
        $this->times->attach($fiber, $timestamp);
        parent::insert($fiber);
    }

    public function insert(mixed $value): true {
        throw new LogicException("Can't insert this way");
    }

    public function getNextTimestamp(): ?float {
        if ($this->isEmpty()) {
            return null;
        }
        return $this->times[$this->current()];
    }

    public function extract(): mixed {
        if ($this->isEmpty()) return null;
        $fiber = parent::extract();
        $this->times->detach($fiber);
        return $fiber;
    }

    public function contains(Fiber $fiber): bool {
        return $this->times->contains($fiber);
    }

    public function cancel(Fiber $fiber): void {
        $buffer = [];
        if ($this->contains($fiber)) {
            while (!$this->isEmpty()) {
                $existing = parent::extract();
                if ($existing === $fiber) {
                    $this->times->detach($existing);
                    break;
                }
                $buffer[] = $existing;
            }
            foreach ($buffer as $existing) {
                parent::insert($existing);
            }
        }
    }

    protected function compare(mixed $value1, mixed $value2): int {
        $t1 = $this->times[$value1];
        $t2 = $this->times[$value2];
        if ($t1 < $t2) return 1;
        else if ($t1 > $t2) return -1;
        return 0;
    }    
}