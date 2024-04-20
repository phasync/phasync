<?php
namespace phasync;

use Evenement\EventEmitter;
use Fiber;
use LogicException;
use stdClass;
use Throwable;

/**
 * Represents a message channel which can be used to communicate between
 * coroutines. Each message will be received by only one subscriber.
 * 
 * @package phasync
 */
final class DangerousChannel extends EventEmitter implements ReadableChannelInterface, WritableChannelInterface {

    protected int $bufferSize;
    protected array $buffer = [];
    protected int $nextRead = 0;
    protected int $nextWrite = 0;
    private bool $closed = false;

    public function __construct(int $bufferSize = 0) {
        $this->bufferSize = \max(0, $bufferSize);
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
     */
    public function close(): void {
        if ($this->isClosed()) {
            throw new LogicException("Channel already closed");
        }
        $this->closed = true;
        $this->emit('close');
    }

    /**
     * Even if suspended coroutines may hold references to the channel,
     * the channel may be destructed if there are no active coroutines
     * holding a reference.
     * 
     * @return void 
     * @throws LogicException 
     */
    public function __destruct() {
        if (!$this->isClosed()) {
            // Ensure any suspended coroutines are resumed
            $this->close();
        }
    }

    /**
     * Write a single message to the next reader in queue. Writing will
     * block if the buffer is full and there are no readers in queue.
     * 
     * @param mixed $message 
     * @return void 
     * @throws LogicException 
     * @throws Throwable 
     */
    public function write(mixed $message): void {
        self::assertFiber();

        $messageId = $this->nextWrite++;
        $this->buffer[$messageId] = $message;

        // Wait until the message is valid for release
        while ($messageId > $this->nextRead && $this->bufferSize <= $this->nextWrite - $this->nextRead) {
            $this->assertNotClosed();
            //echo "write (id=$messageId nextRead=" . $this->nextRead . " nextWrite=" . $this->nextWrite . ")\n";
            Loop::yield();
        }

        return;
    }
    
    /**
     * Read a message from the next writer in queue. Reading will block
     * if no messages are waiting.
     * 
     * @return mixed 
     * @throws LogicException 
     * @throws Throwable 
     */
    public function read(): mixed {
        self::assertFiber();

        // Wait until there is a readable message
        while ($this->nextRead === $this->nextWrite && !$this->closed) {
            Loop::yield();
        }

        if ($this->nextRead < $this->nextWrite) {
            $message = $this->buffer[$this->nextRead];
            unset($this->buffer[$this->nextRead++]);
            return $message; 
        }

        return null;
    }

    private static function assertFiber(): void {
        if (Fiber::getCurrent() === null) {
            throw new UsageError("Must be invoked from within a coroutine");
        }
    }

    private function assertNotClosed(): void {
        if ($this->isClosed()) {
            throw new LogicException("Channel is closed");
        }
    }

}