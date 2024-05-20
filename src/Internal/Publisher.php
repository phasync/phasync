<?php
namespace phasync\Internal;

use IteratorAggregate;
use phasync;
use phasync\ReadChannelInterface;
use phasync\TimeoutException;
use phasync\WriteChannelInterface;
use stdClass;
use Throwable;
use Traversable;

/**
 * This class will read all messages from a ReadChannelInterface object, and allow
 * other coroutines to subscribe to all messages emitted from that readchannel via
 * a subscription object.
 * 
 * @internal
 * @package phasync
 */
final class Publisher implements IteratorAggregate {

    private ReadChannelInterface $readChannel;
    private ChannelMessage $lastMessage;
    private stdClass $requestReadFlag;
    private stdClass $notifyMessageFlag;

    public function __construct(ReadChannelInterface $readChannel) {
        $this->readChannel = $readChannel;
        $this->lastMessage = new ChannelMessage;
        $this->requestReadFlag = new stdClass;
        $this->notifyMessageFlag = new stdClass;

        phasync::service(function() {
            do {
                phasync::awaitFlag($this->requestReadFlag);
                $message = $this->readChannel->read();
                $this->lastMessage->message = $message;
                $this->lastMessage->next = new ChannelMessage;
                $this->lastMessage = $this->lastMessage->next;
                phasync::raiseFlag($this->notifyMessageFlag);
            } while (!$this->readChannel->isClosed());
        });
    }

    public function getSelectManager(): SelectManager {
        return $this->readChannel->getSelectManager();
    }

    /**
     * Get a new subscription for the read channel.
     * 
     * @return Subscriber 
     */
    public function subscribe(): Subscriber {
        return new Subscriber($this);
    }

    /**
     * Subscribe to messages via a generator.
     * 
     * @return Traversable<mixed, mixed> 
     */
    public function getIterator(): Traversable {
        yield from $this->subscribe();
    }

    /**
     * Returns the first message that is available
     * 
     * @internal
     * @return ChannelMessage 
     */
    public function getStartMessage(): ChannelMessage {
        return $this->lastMessage;
    }

    /**
     * Subscribers will be suspended until more data is available
     * from the read channel.
     * 
     * @internal
     * @return void 
     * @throws TimeoutException 
     * @throws Throwable 
     */
    public function wait(): void {
        phasync::raiseFlag($this->requestReadFlag);
        phasync::awaitFlag($this->notifyMessageFlag);
    }
}
