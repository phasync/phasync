<?php
namespace phasync;

use Closure;
use Evenement\EventEmitter;
use LogicException;
use phasync\Channel\ReadableChannel;
use phasync\Channel\ReadableChannelInterface;
use phasync\Channel\WritableChannelInterface;
use WeakMap;

/**
 * Provides a method for publishing messages to multiple coroutines at
 * once. Readers will block until a message becomes available.
 * 
 * @package phasync
 */
final class Publisher extends EventEmitter implements WritableChannelInterface {

    private bool $closed = false;
    private int $offset = 0;
    private array $buffer = [];

    /**
     * Tracks the offset of all readers, to ensure we can clear the buffer
     * as soon as items are read.
     * 
     * @var WeakMap<Closure, int>
     */
    private WeakMap $readers;

    public function __construct() {
        $this->readers = new WeakMap();
    }

    public function close(): void {
        if ($this->isClosed()) {
            throw new LogicException("Publisher is already closed");
        }
        Loop::raiseFlag($this->readers);
        $this->purge();
        $this->closed = true;
    }

    public function subscribe(): ReadableChannelInterface {
        $this->purge();
        $offset = $this->offset;
        $currentOffset = &$this->offset;
        $readers = $this->readers;
        $closed = &$this->closed;
        $buffer = &$this->buffer;

        $readFunction = static function() use (&$readFunction, &$currentOffset, &$closed, &$buffer, $readers) {
            $offset = $readers[$readFunction];
            while ($offset === $currentOffset && !$closed) {
                Loop::awaitFlag($readers);
            }
            if ($currentOffset === $offset && $closed) {
                return null;
            }
            $readers[$readFunction] = $offset + 1;
            return $buffer[$offset];
        };

        $this->readers[$readFunction] = $offset;

        return new ReadableChannel(
            $readFunction,
            static function() use (&$closed) { return $closed; },
            static function() use (&$currentOffset, &$closed, &$readFunction, $readers) {
                return $readers[$readFunction] === $currentOffset && !$closed;
            }
        );
    }

    public function write(mixed $value): void {
        if ($this->isClosed()) {
            throw new LogicException("Can't write to a closed publisher");
        }
        $this->purge();
        $this->buffer[$this->offset++] = $value;
        Loop::raiseFlag($this->readers);
    }

    public function willBlock(): bool {
        return false;
    }

    public function isClosed(): bool { 
        return $this->closed;
    }

    /**
     * Purge buffered items that no subscriber will ever read
     * 
     * @return void 
     */
    private function purge(): void {
        $minOffset = $this->offset;
        foreach ($this->readers as $reader => $offset) {
            if ($offset < $minOffset) {
                $minOffset = $offset;
            }
        }
        while (isset($this->buffer[--$minOffset])) {
            unset($this->buffer[$minOffset]);
        }
    }

}