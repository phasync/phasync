<?php

use phasync\Util\LockTrait;

final class Inventory
{
    use LockTrait;

    private array $stock = ['apple' => 1];

    public function take(string $item): bool
    {
        return $this->lock(function () use ($item) {
            $left = $this->stock[$item] ?? 0;
            phasync::sleep(0.01);         // a database round trip, say
            if ($left < 1) {
                return false;
            }
            $this->stock[$item] = $left - 1;

            return true;
        });
    }
}

phasync::run(function () {
    $inventory = new Inventory();
    foreach (['ann', 'bob'] as $who) {
        phasync::go(function () use ($inventory, $who) {
            $got = $inventory->take('apple');
            echo $who, $got ? ' got the apple' : ' got nothing', "\n";
        });
    }
});
