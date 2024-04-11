<?php
namespace phasync;

use Evenement\EventEmitter;
use Fiber;
use FiberError;
use LogicException;
use SplQueue;
use Throwable;
use WeakMap;

/**
 * Represents a message channel which can be used to communicate between
 * coroutines. Each message will be received by only one subscriber.
 * 
 * @package phasync
 */
final class Channel extends EventEmitter {

    protected int $bufferSize;
    protected array $buffer = [];
    protected array $suspendedReaders = [];
    protected array $suspendedWriters = [];
    private bool $closed = false;

    public function __construct(int $bufferSize = 0) {
        $this->bufferSize = $bufferSize;
    }

    /**
     * Returns true if the channel is closed.
     * 
     * @return bool 
     */
    public function isClosed(): bool {
        return $this->closed;
    }

    /**
     * Close the channel, reviving any blocked coroutines.
     * 
     * @return void 
     * @throws LogicException 
     * @throws FiberError 
     */
    public function close(): void {
        if ($this->isClosed()) {
            throw new LogicException("Channel already closed");
        }
        $this->closed = true;
        foreach ($this->suspendedReaders as $reader) {
            Loop::enqueue($reader);
        }
        $this->suspendedReaders = [];
        foreach ($this->suspendedWriters as $writer) {
            Loop::enqueue($writer);
        }
        $this->suspendedWriters = [];
        $this->emit('close');
    }

    /**
     * Even if suspended coroutines may hold references to the channel,
     * the channel may be destructed if there are no active coroutines
     * holding a reference.
     * 
     * @return void 
     * @throws LogicException 
     * @throws FiberError 
     */
    public function __destruct() {
        if (!$this->isClosed()) {
            // Ensure any suspended coroutines are resumed
            $this->close();
        }
    }

    /**
     * Activate a suspended reader
     * 
     * @return void 
     * @throws FiberError 
     */
    private function activateReader(): void {
        // Activate a single suspended reader
        if (count($this->suspendedReaders) > 0) {
            Loop::enqueue(\array_shift($this->suspendedReaders));
        }
    }

    /**
     * Activate a suspended writer
     * @return void 
     * @throws FiberError 
     */
    private function activateWriter(): void {
        // Activate a single suspended writer
        if (count($this->suspendedWriters) > 0) {
            Loop::enqueue(\array_shift($this->suspendedWriters));
        }
    }

    /**
     * Write a single message to the next reader in queue. Writing will
     * block if the buffer is full and there are no readers in queue.
     * 
     * @param mixed $message 
     * @return void 
     * @throws LogicException 
     * @throws FiberError 
     * @throws Throwable 
     */
    public function write(mixed $message): void {
        self::assertFiber();
        if ($this->isClosed()) {
            throw new LogicException("Channel is closed");
        }
        while (count($this->buffer) >= $this->bufferSize) {
            $this->activateReader();
            $this->suspendedWriters[] = Fiber::getCurrent();
            Fiber::suspend();
            if ($this->isClosed()) {
                throw new LogicException("Channel closed while writing");
            }
        }
        $this->buffer[] = $message;
        return;
    }
    
    /**
     * Read a message from the next writer in queue. Reading will block
     * if no messages are waiting.
     * 
     * @return mixed 
     * @throws LogicException 
     * @throws FiberError 
     * @throws Throwable 
     */
    public function read(): mixed {
        self::assertFiber();
        if ($this->isClosed()) {
            throw new LogicException("Channel is closed");
        }
        while ($this->buffer === []) {
            $this->activateWriter();
            $this->suspendedReaders[] = Fiber::getCurrent();
            Fiber::suspend();
            if ($this->isClosed()) {
                return null;
            }
        }
        return \array_shift($this->buffer);
    }

    private static function assertFiber(): void {
        if (Fiber::getCurrent() === null) {
            throw new UsageError("Must be invoked from within a coroutine");
        }
    }
}