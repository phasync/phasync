<?php
namespace phasync\Internal;

use Fiber;
use phasync;
use phasync\ChannelException;
use phasync\WriteChannelInterface;
use Serializable;
use WeakReference;

/**
 * 
 * @package phasync
 */
class UnbufferedWriteChannel implements WriteChannelInterface {
    private bool $closed = false;

    /**
     * Contains reader fibers when they are waiting for a write, and the
     * written value when they are resumed.
     * 
     * @var array<int, mixed[]>
     */
    private int $id;
    public bool $hasValue = false;
    public mixed $value = null;
    public ?WeakReference $creator;

    public function __construct() {
        $this->id = \spl_object_id($this);
        $this->creator = WeakReference::create(Fiber::getCurrent());
    }

    public function close(): void {
        $this->closed = true;
        phasync::raiseFlag($this);
    }

    public function isClosed(): bool {
        return $this->closed;
    }

    public function write(Serializable|array|string|float|int|bool $value): void {
        while (!$this->closed && $this->hasValue) {
            phasync::awaitFlag($this);
        }
        if ($this->hasValue) {
            throw new ChannelException("Channel was closed");
        }
        $this->hasValue = true;
        $this->value = $value;
        phasync::raiseFlag($this);
    }

    public function isWritable(): bool {
        return !$this->closed;
    }

    public function readWillBlock(): bool {
        return !$this->hasValue || $this->closed;
    }
}