<?php

// A one-shot gate built on a flag: coroutines wait until it opens
final class Gate
{
    private bool $open = false;

    public function wait(): void
    {
        if (!$this->open) {
            phasync::awaitFlag($this);
        }
    }

    public function open(): void
    {
        $this->open = true;
        phasync::raiseFlag($this);
    }
}

phasync::run(function () {
    $gate = new Gate();
    foreach ([1, 2] as $n) {
        phasync::go(function () use ($gate, $n) {
            $gate->wait();
            echo "visitor $n enters\n";
        });
    }

    phasync::sleep(0.01);
    echo "opening\n";
    $gate->open();
    $gate->wait();   // returns at once now
    echo "the gate stays open\n";
});
