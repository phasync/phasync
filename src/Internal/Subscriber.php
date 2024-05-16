<?php
namespace phasync\Internal;

use LogicException;
use phasync;
use phasync\ReadChannelInterface;
use phasync\WriteChannelInterface;
use Serializable;
use WeakMap;
use WeakReference;

final class Subscriber implements ReadChannelInterface {

    private ?Publisher $publisher;
    private ChannelMessage $currentMessage;

    public function __construct(Publisher $publisher) {
        $this->publisher = $publisher;
        $this->currentMessage = $this->publisher->getStartMessage();

    }

    public function __destruct() {
        $this->close();
    }

    public function close(): void {
        $this->publisher = null;
    }

    public function isClosed(): bool {
        return $this->publisher === null;
    }

    public function read(): Serializable|array|string|float|int|bool|null {
        if ($this->publisher === null) {
            return null;
        }
        if (!$this->currentMessage->next) {
            $this->publisher->readMore();
        }
        $message = $this->currentMessage->message;
        $this->currentMessage = $this->currentMessage->next;
        if ($message === null) {
            $this->close();
        }
        return $message;
    }

    public function isReadable(): bool {
        return $this->publisher !== null;
    }

    public function readWillBlock(): bool {
        if (!$this->publisher) {
            return false;
        }
        if ($this->currentMessage->next) {
            return false;
        }
        return true;
    }
}

