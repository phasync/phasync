<?php
namespace phasync\Internal;

use phasync\WriteChannelInterface;
use Serializable;

final class WriteChannel implements WriteChannelInterface {
    private readonly int $_id;
    private ?ChannelState $state;

    public function __construct(ChannelState $state) {
        $this->_id = \spl_object_id($this);
        $this->state = $state;
    }

    public function __destruct() {
        $this->close();
        if (--$this->state->refCount === 0) {
            $this->state->returnToPool();
            $this->state = null;   
        }
    }

    public function close(): void {
        $this->state->close();
    }

    public function write(Serializable|array|string|float|int|bool $value): void {
        $this->state->write($value);
    }

    public function isWritable(): bool {
        return $this->state->isWritable();
    }

    public function willBlock(): bool {
        return $this->state->willWriteBlock();
    }

}