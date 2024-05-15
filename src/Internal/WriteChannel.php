<?php
namespace phasync\Internal;

use phasync\WriteChannelInterface;
use Serializable;

final class WriteChannel implements WriteChannelInterface {

    private ChannelBackendInterface $channel;

    public function __construct(ChannelBackendInterface $channel) {
        $this->channel = $channel;
    }

    public function __destruct() {
        $this->close();
    }

    public function close(): void {
        $this->channel->close();
    }

    public function isClosed(): bool {
        return $this->channel->isClosed();
    }

    public function write(Serializable|array|string|float|int|bool $value): void {
        $this->channel->write($value);
    }

    public function isWritable(): bool {
        return $this->channel->isWritable();
    }

    public function writeWillBlock(): bool {
        return $this->channel->writeWillBlock();
    }

}