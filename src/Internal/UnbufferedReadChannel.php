<?php
namespace phasync\Internal;

use Fiber;
use phasync;
use phasync\ChannelException;
use phasync\ReadChannelInterface;
use Serializable;
use WeakReference;

final class UnbufferedReadChannel implements ReadChannelInterface {

    /**
     * A reference to the source.
     * 
     * @var WeakReference<UnbufferedWriteChannel>
     */
    private WeakReference $source;

    public function __construct(UnbufferedWriteChannel $source) {
        $this->source = WeakReference::create($source);
    }

    public function __destruct() {
        $this->close();
    }

    public function close(): void {
        $this->source->get()?->close();
    }

    public function isClosed(): bool {
        return $this->source->get()?->isClosed() ?? true;
    }

    public function read(): Serializable|array|string|float|int|bool|null {
        while (!$this->isClosed() && !$this->source->get()->hasValue) {
            phasync::awaitFlag($this->source->get());
        }
        $source = $this->source->get();
        if (!$source || !$source->hasValue) {
            return null;
        }
        
        $value = $source->value;
        $source->value = null;
        $source->hasValue = false;
        phasync::raiseFlag($source);
        return $value;
    }

    public function isReadable(): bool {
        return !$this->isClosed();
    }

    public function readWillBlock(): bool {
        return $this->isClosed() || $this->source->get()?->hasValue;
    }
}