<?php

namespace phasync\Internal;

use phasync\ChannelException;
use phasync\ReadChannelInterface;
use phasync\TimeoutException;

/**
 * This class will read all messages from a ReadChannelInterface object, and allow
 * other coroutines to subscribe to all messages emitted from that readchannel via
 * a subscription object.
 *
 * @internal
 */
final class Subscribers implements \IteratorAggregate
{
    /**
     * @var \WeakMap<ReadChannelInterface, array>|null
     */
    private static ?\WeakMap $receiverStates = null;

    private ReadChannelInterface $readChannel;
    private ChannelMessage $lastMessage;
    private \stdClass $notifyMessageFlag;
    private int $waiting = 0;
    private bool $closed = false;
    private ?\Fiber $creatingFiber;

    public function __construct(ReadChannelInterface $readChannel)
    {
        if (null === self::$receiverStates) {
            self::$receiverStates = new \WeakMap();
        }
        $notifyMessageFlag   = $this->notifyMessageFlag = new \stdClass();
        $lastMessage         = $this->lastMessage = new ChannelMessage();
        $waiting             = &$this->waiting;
        $closed              = &$this->closed;
        $this->readChannel   = $readChannel;
        $this->creatingFiber = \phasync::getFiber();

        \phasync::service(static function () use ($lastMessage, $notifyMessageFlag, $readChannel, &$waiting, &$closed) {
            try {
                do {
                    \phasync::yield();
                    if ($closed) {
                        break;
                    }
                    if (0 === $waiting) {
                        continue;
                    }
                    $message              = $readChannel->read();
                    $lastMessage->message = $message;
                    $lastMessage->next    = new ChannelMessage();
                    $lastMessage          = $lastMessage->next;
                    if ($waiting > 0) {
                        \phasync::raiseFlag($notifyMessageFlag);
                    }
                } while (null !== $message);
            } finally {
                $readChannel->close();
            }
        });
    }

    public function __destruct()
    {
        $this->closed = true;
    }

    public function getSelectManager(): SelectManager
    {
        return $this->readChannel->getSelectManager();
    }

    /**
     * Get a new subscription for the read channel.
     */
    public function subscribe(): Subscriber
    {
        if ($this->creatingFiber) {
            if (\phasync::getFiber() === $this->creatingFiber) {
                throw new ChannelException("Can't subscribe to a publisher from the coroutine that created it");
            }
            $this->creatingFiber = null;
        }

        return new Subscriber($this);
    }

    /**
     * Subscribe to messages via a generator.
     *
     * @return \Traversable<mixed, mixed>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->subscribe();
    }

    /**
     * Returns the first message that is available
     *
     * @internal
     */
    public function getStartMessage(): ChannelMessage
    {
        while ($this->lastMessage->next) {
            $this->lastMessage = $this->lastMessage->next;
        }

        return $this->lastMessage;
    }

    /**
     * Subscribers will be suspended until more data is available
     * from the read channel.
     *
     * @internal
     *
     * @throws TimeoutException
     * @throws \Throwable
     */
    public function wait(): void
    {
        try {
            ++$this->waiting;
            \phasync::awaitFlag($this->notifyMessageFlag);
        } finally {
            --$this->waiting;
        }
    }
}
