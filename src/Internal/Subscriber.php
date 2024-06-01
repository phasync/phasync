<?php
namespace phasync\Internal;

use IteratorAggregate;
use phasync;
use phasync\ReadChannelInterface;
use phasync\TimeoutException;
use RuntimeException;
use Serializable;
use Throwable;
use Traversable;

/**
 * This class is created via phasync::publisher()
 * 
 * @internal
 * @package phasync\Internal
 */
final class Subscriber implements ReadChannelInterface, IteratorAggregate {
    private int $id;
    private ?Subscribers $publisher;
    private ChannelMessage $currentMessage;

    public function __construct(Subscribers $publisher) {
        $this->id = \spl_object_id($this);
        $this->publisher = $publisher;
        $this->currentMessage = $this->publisher->getStartMessage();
    }

    public function activate(): void {
        throw new RuntimeException("Can't activate a subscriber this way. Use the publisher instead.");
    }

    /**
     * Wait for data without reading
     * 
     * @return void 
     * @throws TimeoutException 
     * @throws Throwable 
     */
    public function await(): void {
        if ($this->selectWillBlock()) {
            $this->getSelectManager()->await();
        }
    }

    /**
     * Interface for the phasync::select() 
     * 
     * @return SelectManager 
     */
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
