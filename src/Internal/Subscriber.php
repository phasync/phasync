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
    public function await(): void
    {
        if (!$this->isClosed()) {
            $this->publisher->waitForMessage();
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
        while (null !== ($message = $this->read())) {
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

    public function read(): \Serializable|array|string|float|int|bool|null
    {
        if ($this->isClosed()) {
            return null;
        }
        if (!$this->currentMessage->next) {
            $this->publisher->waitForMessage();
        }
        $message              = $this->currentMessage->message;
        $this->currentMessage = $this->currentMessage->next;
        if ($this->currentMessage->next === $this->currentMessage) {
            $this->close();
        }

        return $message;
    }

    public function isReadable(): bool
    {
        return !$this->isClosed();
    }
}
