<?php

use phasync\Context\ContextFactoryInterface;

final class RequestContextFactory implements ContextFactoryInterface
{
    public int $created = 0;

    public function createContext(): object
    {
        ++$this->created;

        return new stdClass();   // a new object each time: a context is used once
    }
}

phasync::run(function () {
    $factory = new RequestContextFactory();

    phasync::withContext(fn () => 'a static page', $factory);
    echo "after a request that started nothing: {$factory->created} contexts\n";

    $factory = new RequestContextFactory();
    phasync::withContext(fn () => phasync::go(fn () => phasync::sleep(0.01)), $factory);
    echo "after a request that started a coroutine: {$factory->created} context\n";
});
