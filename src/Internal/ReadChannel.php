<?php

namespace phasync\Internal;

use phasync\ReadChannelInterface;

/**
 * This object is the readable end of a phasync channel. If it is garbage
 * collected, the writable end of the channel will also be closed. Messages
 * can be read via the {@see ReadChannel::read()} method, or by using the
 * ReadChannel as an iterator, for example with foreach().
 */
final class ReadChannel implements ReadChannelInterface, \IteratorAggregate
{
    private ChannelBackendInterface $channel;

    public function __construct(ChannelBackendInterface $channel)
    {
        $this->channel = $channel;
    }

    public function getSelectManager(): SelectManager
    {
        return $this->channel->getSelectManager();
    }

    public function selectWillBlock(): bool
    {
        return $this->channel->readWillBlock();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function getIterator(): \Traversable
    {
        while (null !== ($message = $this->read())) {
            yield $message;
        }
    }

    public function read(): \Serializable|array|string|float|int|bool|null
    {
        return $this->channel->read();
    }

    public function isReadable(): bool
    {
        return $this->channel->isReadable();
    }

    public function close(): void
    {
        $this->channel->close();
    }

    public function isClosed(): bool
    {
        return $this->channel->isClosed();
    }
}
