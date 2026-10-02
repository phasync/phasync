<?php

namespace phasync;

use IteratorAggregate;

/**
 * A subscription to a publisher: a reading end that receives every message published after it was made.
 *
 * It is read like any ReadChannelInterface, with `read()` or `foreach`. A subscription that falls behind does not hold the publisher or the other subscriptions back, and it ends once the publisher is closed and its messages are read.
 *
 * ```php
 * phasync::run(function () {
 *     phasync::publisher($subscribers, $publisher);
 *     $subscription = $subscribers->subscribe();
 *
 *     phasync::go(function () use ($publisher) {
 *         $publisher->write('a');
 *         $publisher->write('b');
 *         $publisher->close();
 *     });
 *
 *     foreach ($subscription as $message) {
 *         echo $message, "\n";      // a, then b
 *     }
 * });
 * ```
 *
 * @see phasync::publisher
 * @see phasync\SubscribersInterface::subscribe
 */
interface SubscriberInterface extends ReadChannelInterface, IteratorAggregate
{
}
