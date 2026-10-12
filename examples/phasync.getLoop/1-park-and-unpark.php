<?php

// A one-value mailbox on the loop's park/unpark API, for code that owns its waits
final class Mailbox
{
    private ?int $slot = null;
    private mixed $value = null;

    public function receive(): mixed
    {
        $loop       = phasync::getLoop();
        $this->slot = $loop->getSlot();
        $loop->park($this->slot);

        return $this->value;
    }

    public function send(mixed $value): void
    {
        $this->value = $value;
        phasync::getLoop()->unpark($this->slot);
    }
}

phasync::run(function () {
    $mailbox = new Mailbox();
    phasync::go(function () use ($mailbox) {
        echo 'received: ', $mailbox->receive(), "\n";
    });
    $mailbox->send('hello');
});
