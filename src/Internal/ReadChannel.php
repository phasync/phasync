<?php

namespace phasync\Internal;

use phasync\ReadChannelInterface;
use stdClass;

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
    private object $flag;

    public function __construct(ChannelBackendInterface $channel)
    {
        $this->id      = \spl_object_id($this);
        $this->channel = $channel;
        $this->flag = new stdClass;
    }

    public function activate(): void
    {
        $this->channel->activate();
    }

    public function isReady(): bool
    {
        return $this->channel->isReadyForRead();
    }

    public function await(): void
    {
        $this->channel->awaitReadable();
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
