<?php
namespace phasync\Internal;

use phasync\ReadChannelInterface;
use Serializable;

final class ReadChannel implements ReadChannelInterface {

    private ChannelBackendInterface $channel;

    public function __construct(ChannelBackendInterface $channel) {
        $this->channel = $channel;
        
    }

    public function __destruct() {
        $this->close();
    }

    public function read(): Serializable|array|string|float|int|bool|null {
        return $this->channel->read();
    }

    public function isReadable(): bool {
        return $this->channel->isReadable();
    }

    public function readWillBlock(): bool {
        return $this->channel->readWillBlock();
    }

    public function close(): void {
        $this->channel->close();
    }

    public function isClosed(): bool {
        return $this->channel->isClosed();
    }
}