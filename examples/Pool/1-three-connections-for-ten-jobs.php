<?php

use phasync\Util\Pool;

final class Connection
{
    public function __construct(public readonly int $id)
    {
    }

    public function query(string $sql): string
    {
        phasync::sleep(0.02);   // the round trip to the server
        return "result of $sql";
    }
}

phasync::run(function () {
    $made = 0;
    $pool = new Pool(function () use (&$made) {
        return new Connection(++$made);
    }, size: 3);

    for ($job = 1; $job <= 10; ++$job) {
        phasync::go(fn () => $pool->use(fn (Connection $db) => $db->query("SELECT $job")));
    }
    phasync::sleep(0.2);

    echo "10 jobs ran on $made connections\n";
    echo count($pool), " connections in the pool\n";
});
