<?php
namespace phasync\Internal;

final class ChannelMessage {
    public ?ChannelMessage $next = null;
    public mixed $message;

    public function __construct(mixed $message) {
        $this->message = $message;
    }
}