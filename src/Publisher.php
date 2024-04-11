<?php
namespace phasync;

use Evenement\EventEmitter;
use Fiber;
use FiberError;
use LogicException;
use Throwable;
use WeakMap;

/**
 * Provides a method for publishing messages to multiple coroutines at
 * once. Readers will block until a message becomes available.
 * 
 * @package phasync
 */
final class Publisher extends EventEmitter {
    /**
     * All subscriber channels
     * 
     * @var Channel[]
     */
    private array $subscribers = [];
    private int $subscriberKey = 0;
    private bool $closed = false;

    public function subscribe(): Channel {
        if ($this->isClosed()) {
            throw new LogicException("Publisher is closed");
        }
        $channel = new Channel();
        $key = $this->subscriberKey++;
        $channel->on('close', function() use ($key) {
            unset($this->subscribers[$key]);
        });
        $this->subscribers[$key] = $channel;

        return $channel;
    }

    public function isClosed(): bool {
        return $this->closed;
    }

    /**
     * Close the publisher and all associated subscriptions.
     * 
     * @return void 
     * @throws LogicException 
     * @throws FiberError 
     */
    public function close(): void {
        if ($this->isClosed()) {
            throw new LogicException("Publisher is already closed");
        }
        $this->closed = true;
        foreach ($this->subscribers as $subscriber) {
            if (!$subscriber->isClosed()) {
                $subscriber->close();
            }
        }
        $this->emit('closed');
    }

    /**
     * Publish a message to any subscribed readers. 
     * 
     * @param mixed $message 
     * @return void 
     * @throws FiberError 
     * @throws Throwable 
     */
    public function publish(mixed $message) {
        if ($this->isClosed()) {
            throw new LogicException("Publisher is closed");
        }
        self::assertFiber();
        foreach ($this->subscribers as $subscriber) {
            if (!$subscriber->isClosed()) {
                $subscriber->write($message);
            }
        }
    }

    private static function assertFiber(): void {
        if (Fiber::getCurrent() === null) {
            throw new UsageError("Must be invoked from within a coroutine");
        }
    }
}