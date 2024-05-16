<?php
namespace phasync\Internal;

use phasync;
use phasync\ReadChannelInterface;
use phasync\WriteChannelInterface;
use stdClass;

/**
 * @internal
 * @package phasync\Internal
 */
final class Publisher {

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

    /**
     * Returns the first message that is available
     * 
     * @return ChannelMessage 
     */
    public function getStartMessage(): ChannelMessage {
        return $this->lastMessage;
    }

    public function readMore(): void {
        phasync::raiseFlag($this->requestReadFlag);
        phasync::awaitFlag($this->notifyMessageFlag);
    }

    public function __destruct() {
        echo "Destruct publisher\n";
    }

    public function subscribe(): Subscriber {
        return new Subscriber($this);
    }
}