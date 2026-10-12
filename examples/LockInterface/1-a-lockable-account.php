<?php

use phasync\Util\LockInterface;
use phasync\Util\LockTrait;

final class Account implements LockInterface
{
    use LockTrait;

    public int $balance = 100;
}

function withdraw(LockInterface&Account $account, int $amount): void
{
    $account->lock(function () use ($account, $amount) {
        $balance = $account->balance;
        phasync::sleep(0.01);              // without the lock, the other withdrawal would read 100 too
        $account->balance = $balance - $amount;
    });
}

phasync::run(function () {
    $account = new Account();
    phasync::go(fn () => withdraw($account, 30));
    phasync::go(fn () => withdraw($account, 50));
    phasync::sleep(0.05);
    echo "balance: {$account->balance}\n";
});
