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
    private int $id;

    private ChannelBackendInterface $channel;

    public function __construct(ChannelBackendInterface $channel)
    {
        $this->id      = \spl_object_id($this);
        $this->channel = $channel;
    }

    public function activate(): void
    {
        $this->channel->activate();
    }

    public function isReady(): bool
    {
        return $this->channel->isReadyForRead();
    }

    public function await(float $timeout = \PHP_FLOAT_MAX): void
    {
        $this->channel->awaitReadable($timeout);
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

    public function read(float $timeout = \PHP_FLOAT_MAX): \Serializable|array|string|float|int|bool|null
    {
        return $this->channel->read($timeout);
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
