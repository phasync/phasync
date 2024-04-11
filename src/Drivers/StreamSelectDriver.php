<?php
namespace phasync\Drivers;

use Fiber;
use LogicException;
use phasync\DriverInterface;
use phasync\Util\Debug;
use phasync\Util\Scheduler;
use SplQueue;
use TypeError;
use WeakMap;

class StreamSelectDriver implements DriverInterface {    

    /**
     * Fibers that are ready to be resumed.
     * 
     * @var SplQueue<Fiber>
     */
    private SplQueue $queue;

    /**
     * Fibers that will be enqueued whenever the event
     * is idle.
     */
    private SplQueue $idleQueue;

    /**
     * Fibers that are scheduled to run in the future
     * 
     * @var Scheduler
     */
    private Scheduler $scheduler;

    /**
     * All streams being polled for readability
     * 
     * @var array<int,resource>
     */
    private array $readable = [];

    /**
     * All streams being polled for writability
     * @var array<int,resource>
     */
    private array $writable = [];

    /**
     * Maps the stream resource to a Fiber instance for readable and writable polling
     * 
     * @return array<int,Fiber> 
     */
    private array $streamFibers = [];

    /**
     * Holds a reference to fibers that are currently pending the event loop.
     * 
     * @var WeakMap
     */
    private WeakMap $pendingFibers;

    public function __construct() {
        $this->queue = new SplQueue();
        $this->idleQueue = new SplQueue();
        $this->scheduler = new Scheduler();
        $this->pendingFibers = new WeakMap();
    }

    public function isPending(Fiber $fiber): bool {
        return isset($this->pendingFibers[$fiber]);
    }

    public function count(): int { 
        return $this->pendingFibers->count();
    }

    public function tick(float $maxSleepTime = 1): void {
        if (Fiber::getCurrent() !== null) {
            throw new LogicException("Can't run ticks from within a Fiber");
        }

        // Activate from the scheduler
        $timestamp = \microtime(true);
        while (!$this->scheduler->isEmpty() && $this->scheduler->getNextTimestamp() <= $timestamp) {
            $this->queue->enqueue($this->scheduler->extract());
        }

        // Calculate the max idle duration
        if (!empty($this->queue)) {
            $maxSleepTime = 0;
        } elseif (!$this->scheduler->isEmpty()) {
            $maxSleepTime = \min($maxSleepTime, $this->scheduler->getNextTimestamp() - $timestamp);
        }        
        $maxSleepTime = \max(0, $maxSleepTime);

        // If the loop is idle, schedule any idle fibers
        if ($maxSleepTime > 0 && !$this->idleQueue->isEmpty()) {
            $maxSleepTime = 0;
            while (!$this->idleQueue->isEmpty()) {
                $this->queue->enqueue($this->idleQueue->dequeue());
            }
        }

        // Activate fibers waiting for IO
        if (!empty($this->readable) || !empty($this->writable)) {
            $reads = \array_values($this->readable);
            $writes = \array_values($this->writable);
            $excepts = [];

            $result = \stream_select($reads, $writes, $excepts, \intval($maxSleepTime), ($maxSleepTime - intval($maxSleepTime)) * 1000000);
            if (\is_int($result) && $result > 0) {
                foreach ($reads as $readableStream) {
                    $id = \get_resource_id($readableStream);
                    $this->queue->enqueue($this->streamFibers[$id]);
                    unset($this->streamFibers[$id], $this->readable[$id]);                
                }
                foreach ($writes as $writableStream) {
                    $id = \get_resource_id($writableStream);
                    $this->queue->enqueue($this->streamFibers[$id]);
                    unset($this->streamFibers[$id], $this->writable[$id]);                
                }
            }
        } elseif ($maxSleepTime > 0) {
            \usleep(1000000 * $maxSleepTime);
        }

        // Run queued fibers
        $maxCount = $this->queue->count();
        for ($i = 0; $i < $maxCount && !$this->queue->isEmpty(); $i++) {
            /** @var Fiber */
            $fiber = $this->queue->dequeue();
            if (!isset($this->pendingFibers[$fiber])) {
                throw new LogicException("Dequeued a fiber that was not pending");
            }
            unset($this->pendingFibers[$fiber]);
            $fiber->resume();
        }
    }

    public function enqueue(Fiber $fiber): void {
        if ($this->isPending($fiber)) {
            throw new LogicException(Debug::getDebugInfo($fiber)." is already pending");
        }
        $this->pendingFibers[$fiber] = true;
        $this->queue->enqueue($fiber);
    }

    public function idle(Fiber $fiber): void {
        if ($this->isPending($fiber)) {
            throw new LogicException(Debug::getDebugInfo($fiber) . " is already pending");
        }
        $this->pendingFibers[$fiber] = true;
        $this->idleQueue->enqueue($fiber);
    }

    public function delay(float $seconds, Fiber $fiber): void {
        if ($this->isPending($fiber)) {
            throw new LogicException("Fiber is already pending");
        }
        $this->pendingFibers[$fiber] = true;
        $this->scheduler->schedule($seconds + microtime(true), $fiber);
    }

    public function readable($resource, Fiber $fiber): void {
        if ($this->isPending($fiber)) {
            throw new LogicException("Fiber is already pending");
        }
        $this->pendingFibers[$fiber] = true;
        if (!\is_resource($resource)) {
            throw new TypeError("Expecting a stream resource");
        }
        $resourceId = \get_resource_id($resource);
        if (isset($this->streamFibers[$resourceId])) {
            throw new LogicException("Already blocking a Fiber for this resource");
        }
        $this->streamFibers[$resourceId] = $fiber;
        $this->readable[$resourceId] = $resource;
    }

    public function writable($resource, Fiber $fiber): void {
        if ($this->isPending($fiber)) {
            throw new LogicException("Fiber is already pending");
        }
        $this->pendingFibers[$fiber] = true;
        if (!\is_resource($resource)) {
            throw new TypeError("Expecting a stream resource");
        }
        $resourceId = \get_resource_id($resource);
        if (isset($this->streamFibers[$resourceId])) {
            throw new LogicException("Already blocking a Fiber for this resource");
        }
        $this->streamFibers[$resourceId] = $fiber;
        $this->writable[$resourceId] = $resource;
    }

    public function cancel(Fiber $fiber): bool {
        if (!$this->isPending($fiber)) {
            return false;
        }
        // Scheduler is fast to check, so check it first
        if ($this->scheduler->contains($fiber)) {
            unset($this->pendingFibers[$fiber]);
            $this->scheduler->cancel($fiber);
            return true;
        }

        // Most likely place for a fiber to be held
        $index = \array_search($fiber, $this->streamFibers, true);
        if (\is_int($index)) {
            unset($this->readable[$index]);
            unset($this->writable[$index]);
            unset($this->streamFibers[$index]);
            unset($this->pendingFibers[$fiber]);
            return true;
        }

        // Clean up idle fibers
        if (!$this->idleQueue->isEmpty()) {
            $oldQueue = $this->idleQueue;
            $this->idleQueue = new SplQueue();
            $result = false;
            foreach ($oldQueue as $heldFiber) {
                if ($heldFiber === $fiber) {
                    unset($this->pendingFibers[$fiber]);
                    $result = true;
                    continue;
                }
                $this->queue->enqueue($heldFiber);
            }
            if ($result) {
                return true;
            }
        }

        // The final place a fiber may be held
        if (!$this->queue->isEmpty()) {
            $oldQueue = $this->queue;
            $this->queue = new SplQueue();
            $result = false;
            foreach ($oldQueue as $heldFiber) {
                if ($heldFiber === $fiber) {
                    unset($this->pendingFibers[$fiber]);
                    $result = true;
                    continue;
                }
                $this->queue->enqueue($heldFiber);
            }    
        }

        return $result;
    }

}