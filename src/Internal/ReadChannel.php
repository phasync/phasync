<?php
namespace phasync\Internal;

use phasync\ReadChannelInterface;
use Serializable;

final class ReadChannel implements ReadChannelInterface {
    /**
     * This int is required for this instance to be used in a
     * switch-case statement.
     * 
     * @var int
     */
    private readonly int $_;

    private ?ChannelState $state;
    public function __construct(ChannelState $state) {
        $this->_ = \spl_object_id($this);
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

    public function read(): Serializable|array|string|float|int|bool|null {
        return $this->state->read();
    }

    public function isReadable(): bool {
        return $this->state->isReadable();
    }

    public function willBlock(): bool {
        return $this->state->willReadBlock();
    }

}