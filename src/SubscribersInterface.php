<?php

namespace phasync;

/**
 * The subscription point of a publisher: each call to `subscribe()` makes a reader that receives every later message.
 *
 * Iterating over it reads the messages of a new subscription.
 *
 * @see phasync::publisher
 * @see phasync\SubscriberInterface
 */
interface SubscribersInterface extends \IteratorAggregate
{
    /**
     * Creates a subscription that receives the messages published after this call.
     *
     * ```php
     * phasync::run(function () {
     *     phasync::publisher($subscribers, $publisher);
     *
     *     $subscription = $subscribers->subscribe();
     *     phasync::go(function () use ($subscription) {
     *         foreach ($subscription as $event) {
     *             echo "got $event\n";
     *         }
     *     });
     *
     *     $publisher->write('deployed');
     *     $publisher->close();
     * });
     * ```
     *
     * @return SubscriberInterface the subscription
     *
     * @see phasync::publisher
     */
    public function subscribe(): SubscriberInterface;
}
