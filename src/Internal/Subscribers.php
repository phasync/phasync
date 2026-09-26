<?php

namespace phasync\Internal;

use phasync\ReadChannelInterface;
use phasync\SubscriberInterface;
use phasync\SubscribersInterface;
use phasync\TimeoutException;

/**
 * This class will read all messages from a ReadChannelInterface object, and allow
 * other coroutines to subscribe to all messages emitted from that readchannel via
 * a subscription object.
 *
 * @internal
 */
final class Subscribers implements SubscribersInterface
{
    /**
     * @var \WeakMap<ReadChannelInterface, array>|null
     */
    private static ?\WeakMap $receiverStates = null;

    private ChannelMessage $lastMessage;
    private \stdClass $notifyMessageFlag;
    private int $waiting = 0;
    private ReadChannelInterface $readChannel;

    public function __construct(ReadChannelInterface $readChannel)
    {
        if (null === self::$receiverStates) {
            self::$receiverStates = new \WeakMap();
        }
        $notifyMessageFlag   = $this->notifyMessageFlag = new \stdClass();
        $lastMessage         = $this->lastMessage = new ChannelMessage();
        $waiting             = &$this->waiting;
        $this->readChannel   = $readChannel;

        \phasync::service(static function () use ($lastMessage, $notifyMessageFlag, $readChannel, &$waiting) {
            try {
                // Must read unconditionally from the moment the publisher exists, not only
                // once a subscriber is waiting: $readChannel is an unbuffered channel, and
                // write() now always suspends the writer until a reader takes the value
                // (Channel's rendezvous fix). This service IS that reader -- without it
                // reading immediately, a publish with zero subscribers would suspend its
                // writer forever, since nothing would ever be there to receive it. Whether
                // any real Subscriber is listening is a separate concern, handled below by
                // $waiting and the ChannelMessage list, not by delaying the read.
                while (true) {
                    // eof, not "null and isClosed()": a published null racing with close() could
                    // otherwise be misread as end-of-stream and silently dropped, never reaching
                    // any subscriber (the same null-vs-closed ambiguity D1 fixed for read() itself).
                    $message = $readChannel->read(eof: $isEof);
                    if ($isEof) {
                        break;
                    }
                    $lastMessage->message = $message;
                    $lastMessage->next    = new ChannelMessage();
                    $lastMessage          = $lastMessage->next;
                    if ($waiting > 0) {
                        \phasync::raiseFlag($notifyMessageFlag);
                        \phasync::yield();
                    }
                }
            } finally {
                $readChannel->close();
                // A reference to self means there will be no more messages and the channel is closed
                $lastMessage->next = $lastMessage;
                \phasync::raiseFlag($notifyMessageFlag);
            }
        });
    }

    /**
     * Get a new subscription for the read channel.
     */
    public function subscribe(): SubscriberInterface
    {
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
    public function waitForMessage(float $timeout = \PHP_FLOAT_MAX): void
    {
        try {
            ++$this->waiting;
            \phasync::awaitFlag($this->notifyMessageFlag, $timeout);
        } finally {
            --$this->waiting;
        }
    }
}
