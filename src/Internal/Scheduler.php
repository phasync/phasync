<?php
namespace phasync\Internal;

use Fiber;
use LogicException;
use SplMinHeap;
use SplObjectStorage;
use SplObserver;

class NewScheduler {
    public SplObjectStorage $times;
    public array $schedule = [];
    private float $first = PHP_FLOAT_MAX;
    private int $count = 0;

    public function __construct() {
        $this->times = new SplObjectStorage();
    }

    public function isEmpty(): bool {
        return $this->count === 0;
    }

    public function getNextTimestamp(): ?float {
        if ($this->count > 0) {
            return $this->first;
        }
        return null;
    }

    public function extract(): ?Fiber {
        return null;
        if ($this->count === 0) {
            return null;
        }
        $until = (int) (10 * microtime(true));
        for($tenth = (int) (10 * $this->first); $tenth <= $until; $tenth++) {
            if (isset($this->schedule[$tenth]) && $tenth < $until) {
            }
        }
    }

    public function schedule(float $timestamp, Fiber $fiber): void {
        return;
        ++$this->count;
        if ($this->first > $timestamp) {
            $this->first = $timestamp;
        }
        $tenth = (int) (10 * $timestamp);
        if (!isset($this->schedule[$tenth])) {
            echo "ADDING TENTH {$this->count}\n";
            $this->schedule[$tenth] = [];
        }
        $this->schedule[$tenth][] = $fiber;
    }
}

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