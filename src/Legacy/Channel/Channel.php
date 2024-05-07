<?php
namespace phasync\Legacy\Channel;

use Fiber;
use LogicException;
use phasync;
use phasync\Legacy\Channel\ReadableChannel;
use phasync\Legacy\Channel\ReadableChannelInterface;
use phasync\Legacy\Channel\WritableChannel;
use phasync\Legacy\Channel\WritableChannelInterface;
use phasync\UsageError;
use SplQueue;
use WeakMap;
use WeakReference;

/**
 * Represents a channel implementation which runs in the process memory space,
 * sharing data by passing variables. This implementation supports complex objects
 * but will not scale to multiple CPUs.
 * 
 * @package phasync\Channel
 */
final class Channel {
    
    protected int $bufferSize;
    protected SplQueue $buffer;
    protected int $nextRead = 0;
    protected int $nextWrite = 0;
    private bool $closed = false;

    private WeakMap $readers;
    private WeakMap $writers;

    private static ?WeakMap $bufferSafety = null;

    /**
     * Create a pair of readable/writable channels. The channel will automatically be
     * closed if the reference to either end of the channel is garbage collected.
     * 
     * @param int $bufferSize The number of messages that can be buffered
     * @return array{0:ReadableChannelInterface, 1:WritableChannelInterface} One ReadableChannel and one WritableChannel
     */
    public static function create(int $bufferSize = 0): array {
        $channel = new Channel($bufferSize);
        return [ $channel->getReader(), $channel->getWriter() ];
    }

    /**
     * Wait for one of the channels to become readable or writable.
     * 
     * @param ChannelInterface... $channels 
     * @return void 
     */
    public static function select(ChannelInterface... $channels) {
        while (true) {
            foreach ($channels as $channel) {
                if (!$channel->willBlock()) {
                    return $channel;
                }
            }
            phasync::yield();
        }
    }

    /**
     * Create a Channel for passing messages between coroutines.
     * 
     * Generally to avoid potential deadlocks, you should create a
     * reader/writer pair with {@see Channel::create()}.
     * 
     * ```
     * [$reader, $writer] = Channel::create($bufferSize);
     * ```
     * 
     * @param int $bufferSize 
     * @return void 
     */
    public function __construct(int $bufferSize = 0) {
        if (self::$bufferSafety === null) {
            self::$bufferSafety = new WeakMap();
        }
        $this->bufferSize = \max(0, $bufferSize);
        $this->buffer = new SplQueue();
        self::$bufferSafety[$this->buffer] = new class($this->buffer) {
            public function __construct(public readonly SplQueue $buffer) {}
            public function __destruct() {
                if ($this->buffer->count() > 0) {
                    throw new LogicException($this->buffer->count() . " buffered elements lost from Channel");
                }
            }
        };
        $this->readers = new WeakMap();
        $this->writers = new WeakMap();
    }

    /**
     * Returns a readable channel which can be used to read messages
     * from the writable channels.
     * 
     * @return ReadableChannelInterface 
     */
    public function getReader(): ReadableChannelInterface {
        // This ensures the ReadableChannel does not reference the Channel instance
        $nextRead = &$this->nextRead;
        $nextWrite = &$this->nextWrite;
        $closed = &$this->closed;
        $buffer = $this->buffer;
        $writers = $this->writers;
        $channel = WeakReference::create($this);

        $reader = new ReadableChannel(static function() use (&$nextRead, &$nextWrite, &$closed, $buffer, &$writers, $channel): mixed {
            self::assertFiber();

            // Wait until there is a readable message, or no
            // readable messages can be added
            while ($nextRead === $nextWrite && !$closed && ($channel->get() !== null || $writers->count() > 0)) {
                phasync::yield();
            }
    
            if ($nextRead < $nextWrite) {
                $message = $buffer->dequeue();
                $nextRead++;
                return $message; 
            }
    
            return null;    
        }, static function() use (&$closed) { return $closed; }, static function() use (&$nextRead, &$nextWrite, &$closed, $channel, $writers) {
            return $nextRead === $nextWrite && !$closed && ($channel->get() !== null || $writers->count() > 0);
        });

        $this->readers[$reader] = true;

        return $reader;
    }

    public function getWriter(): WritableChannelInterface {
        // This ensures that the WritableChannel does not reference the Channel instance
        $nextRead = &$this->nextRead;
        $nextWrite = &$this->nextWrite;
        $closed = &$this->closed;
        $buffer = $this->buffer;
        $bufferSize = $this->bufferSize;
        $readers = $this->readers;
        $channel = WeakReference::create($this);
        $messageId = null;

        $writer = new WritableChannel(static function(mixed $value) use ($bufferSize, &$nextRead, &$nextWrite, &$closed, $buffer, $readers, $channel, &$messageId): void {
            self::assertFiber();

            $messageId = $nextWrite++;
            $buffer->enqueue($value);
    
            // Wait until the message is valid for release
            while ($messageId >= $nextRead && $bufferSize < $nextWrite - $nextRead) {
                //var_dump($readers->count());
                if ($closed) {
                    throw new LogicException("Channel is closed");
                }
                if ($channel->get() === null && $readers->count() === 0) {
                    throw new LogicException("Channel has no potential readers");
                }
                phasync::yield();
            }
    
            return;    
        }, static function() use (&$closed) { return $closed; }, static function() use (&$messageId, &$nextRead, &$nextWrite, &$bufferSize, &$closed, &$readers, $channel) {
            return ($messageId >= $nextRead && $bufferSize < $nextWrite - $nextRead) || $closed || ($channel->get() === null && $readers->count() === 0);
        });

        $this->writers[$writer] = true;

        return $writer;
    }

    public function close(): void {
        $this->assertNotClosed();
        $this->closed = true;
    }

    public function isClosed(): bool {
        return $this->closed;
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