<?php

// Any object with a then() method is awaited until it settles
final class Deferred
{
    private array $callbacks = [];

    public function then(callable $onFulfilled, callable $onRejected): void
    {
        $this->callbacks[] = $onFulfilled;
    }

    public function resolve(mixed $value): void
    {
        foreach ($this->callbacks as $callback) {
            $callback($value);
        }
    }
}

phasync::run(function () {
    $deferred = new Deferred();
    phasync::go(function () use ($deferred) {
        phasync::sleep(0.01);
        $deferred->resolve('resolved value');
    });

    echo phasync::await($deferred), "\n";
});
