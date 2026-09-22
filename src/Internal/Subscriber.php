<?php

namespace phasync\Internal;

use phasync;
use phasync\SubscriberInterface;
use phasync\TimeoutException;

/**
 * This class is created via phasync::publisher()
 *
 * @internal
 */
final class Subscriber implements SubscriberInterface, \IteratorAggregate
{
    private int $id;
    private ?Subscribers $publisher;
    private ?ChannelMessage $currentMessage;
    private bool $closed = false;

    public function __construct(Subscribers $publisher)
    {
        $this->id             = \spl_object_id($this);
        $this->publisher      = $publisher;
        $this->currentMessage = $this->publisher->getStartMessage();
    }

    public function activate(): void
    {
        throw new \RuntimeException("Can't activate a subscriber this way. Use the publisher instead.");
    }

    /**
     * Wait for data without reading
     *
     * @throws TimeoutException
     * @throws \Throwable
     */
    public function await(float $timeout = \PHP_FLOAT_MAX): void
    {
        if (!$this->isClosed()) {
            $this->publisher->waitForMessage($timeout);
        }
    }

    public function isReady(): bool
    {
        if ($this->closed) {
            return true;
        }
        if (!$this->publisher) {
            return true;
        }
        if ($this->currentMessage->next === $this->currentMessage) {
            return true;
        }

        return false;
    }

    public function getIterator(): \Traversable
    {
        while (true) {
            $message = $this->read(eof: $eof);
            if ($eof) {
                return;
            }
            yield $message;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        $this->publisher = null;
    }

    public function isClosed(): bool
    {
        return null === $this->publisher || $this->currentMessage->next === $this->currentMessage;
    }

    public function read(float $timeout = \PHP_FLOAT_MAX, ?bool &$eof = null): mixed
    {
        $eof = false;
        if ($this->isClosed()) {
            $eof = true;

            return null;
        }
        if (null === $this->currentMessage->next) {
            $this->publisher->waitForMessage($timeout);
        }
        // The wait above may resolve because the chain grew a real next message, or because
        // the publisher closed with nothing more to send -- in which case currentMessage has
        // become the terminal (self-referencing) sentinel node. Its ->message is a meaningless
        // default (null), not a published value, so it must never be returned as one.
        if ($this->currentMessage->next === $this->currentMessage) {
            $this->close();
            $eof = true;

            return null;
        }
        $message              = $this->currentMessage->message;
        $this->currentMessage = $this->currentMessage->next;

        return $message;
    }

    public function isReadable(): bool
    {
        return !$this->isClosed();
    }
}
