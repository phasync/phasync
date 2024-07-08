<?php

namespace phasync\Internal;

use phasync\WriteChannelInterface;

/**
 * This object is the writable end of a phasync channel.
 */
final class WriteChannel implements WriteChannelInterface
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
        return $this->channel->isReadyForWrite();
    }

    public function await(): void
    {
        $this->channel->awaitWritable();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        $this->channel->close();
    }

    public function isClosed(): bool
    {
        return $this->channel->isClosed();
    }

    public function write(\Serializable|array|string|float|int|bool|null $value): void
    {
        $this->channel->write($value);
    }

    public function isWritable(): bool
    {
        return $this->channel->isWritable();
    }

}
