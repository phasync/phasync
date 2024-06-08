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

    public function await(): void
    {
        if ($this->selectWillBlock()) {
            $this->channel->getSelectManager()->await();
        }
    }

    public function getSelectManager(): SelectManager
    {
        return $this->channel->getSelectManager();
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

    public function selectWillBlock(): bool
    {
        return $this->channel->writeWillBlock();
    }
}
