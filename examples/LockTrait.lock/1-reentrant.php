<?php

use phasync\Util\LockTrait;

final class Ledger
{
    use LockTrait;

    private array $lines = [];

    public function add(string $line): void
    {
        $this->lock(function () use ($line) {
            $this->lines[] = $line;
        });
    }

    public function transfer(string $from, string $to, int $amount): void
    {
        // add() takes the lock again from the same coroutine: that is allowed
        $this->lock(function () use ($from, $to, $amount) {
            $this->add("$from -$amount");
            phasync::sleep(0.01);
            $this->add("$to +$amount");
        });
    }

    public function lines(): array
    {
        return $this->lines;
    }
}

phasync::run(function () {
    $ledger = new Ledger();
    phasync::go(fn () => $ledger->transfer('ann', 'bob', 10));
    phasync::go(fn () => $ledger->transfer('bob', 'cid', 5));
    phasync::sleep(0.05);
    echo implode("\n", $ledger->lines()), "\n";   // each transfer's two lines stay together
});
