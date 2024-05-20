<?php
namespace phasync\Internal;

use IteratorAggregate;
use phasync\ReadChannelInterface;
use Serializable;
use Traversable;

final class Subscriber implements ReadChannelInterface, IteratorAggregate {

    private ?Publisher $publisher;
    private ChannelMessage $currentMessage;

    public function __construct(Publisher $publisher) {
        $this->publisher = $publisher;
        $this->currentMessage = $this->publisher->getStartMessage();

    }

    public function getSelectManager(): SelectManager {
        return $this->publisher->getSelectManager();
    }

    public function selectWillBlock(): bool {
        if (!$this->publisher) {
            return false;
        }
        if ($this->currentMessage->next) {
            return false;
        }
        return true;
    }

    public function getIterator(): Traversable {
        while (null !== ($message = $this->read())) {
            yield $message;
        }
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
            $this->publisher->wait();
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

}
