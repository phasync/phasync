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
}
