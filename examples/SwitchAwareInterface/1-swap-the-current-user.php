<?php

use phasync\Context\SwitchAwareInterface;

final class Auth
{
    public static ?string $user = null;
}

final class RequestScope implements SwitchAwareInterface
{
    public function __construct(private readonly string $user)
    {
    }

    public function resume(): void
    {
        Auth::$user = $this->user;
    }

    public function suspend(): void
    {
        Auth::$user = null;
    }
}

phasync::run(function () {
    foreach (['ann' => 0.02, 'bob' => 0.01] as $user => $latency) {
        phasync::go(function () use ($latency) {
            phasync::sleep($latency);   // the other request runs meanwhile
            echo 'serving ', Auth::$user, "\n";
        }, [], new RequestScope($user));
    }
});
