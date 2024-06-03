<?php

namespace phasync\Internal;

use phasync\ReadChannelInterface;

final class Subscriber implements ReadChannelInterface, \IteratorAggregate
{
    private ?Subscribers $publisher;
    private ChannelMessage $currentMessage;

    public function __construct(Subscribers $publisher)
    {
        $this->publisher      = $publisher;
        $this->currentMessage = $this->publisher->getStartMessage();
    }

    public function getSelectManager(): SelectManager
    {
        return $this->publisher->getSelectManager();
    }

    public function selectWillBlock(): bool
    {
        if (!$this->publisher) {
            return false;
        }
        if ($this->currentMessage->next) {
            return false;
        }

        return true;
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
        return null === $this->publisher;
    }

    public function read(): \Serializable|array|string|float|int|bool|null
    {
        if (null === $this->publisher) {
            return null;
        }
        if (!$this->currentMessage->next) {
            $this->publisher->wait();
        }
        $message              = $this->currentMessage->message;
        $this->currentMessage = $this->currentMessage->next;
        if (null === $message) {
            $this->close();
        }

        return $message;
    }

    public function isReadable(): bool
    {
        return null !== $this->publisher;
    }
}
