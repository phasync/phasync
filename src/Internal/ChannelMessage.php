<?php

namespace phasync\Internal;

/**
 * Uses a linked list to ensure multiple subscribers can receive
 * messages from a single readable channel via ChannelSubscriber.
 *
 * @internal
 */
final class ChannelMessage
{
    public mixed $message        = null;
    public ?ChannelMessage $next = null;

    /**
     * A subscriber that lagged behind can be the last reference holding a very long chain of
     * these (one per unread message). Left to PHP's own reference counting, freeing it recurses
     * one call per node and overflows the C stack once the chain is long enough (#113). This walks
     * the chain in a loop instead, unlinking each node right before it would be freed anyway.
     *
     * A node is only unlinked after confirming nothing else still needs it: cutting the `next` of
     * a node another, slower subscriber is still sitting on would corrupt that subscriber's view
     * of the stream. The check is a clear-then-probe: `next` is cleared speculatively (so a
     * same-node recursive free, if it happens, finds nothing left to cascade into), and if the
     * node turns out to still be alive -- something else held a reference to it all along -- the
     * clear is undone and the walk stops there; the rest of the chain belongs to that reference
     * now, not to this one.
     */
    public function __destruct()
    {
        $node       = $this->next;
        $this->next = null;
        while (null !== $node) {
            $next = $node->next;
            if (null === $next || $next === $node) {
                // An open tail (nothing published yet) or the closed sentinel (its own `next`):
                // nothing beyond it to unlink.
                break;
            }
            $node->next = null;
            $weak       = \WeakReference::create($node);
            $node       = null;
            $alive      = $weak->get();
            if (null !== $alive) {
                $alive->next = $next;
                break;
            }
            $node = $next;
        }
    }
}
