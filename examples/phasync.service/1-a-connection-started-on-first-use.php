<?php

final class Connection
{
    private static ?phasync\WriteChannelInterface $queries = null;

    public static function query(string $sql): void
    {
        if (null === self::$queries) {
            phasync::channel($read, self::$queries, 10);
            // A service belongs to no run(): it outlives the request that started it
            phasync::service(function () use ($read) {
                try {
                    foreach ($read as $sql) {
                        phasync::sleep(0.01);
                        echo "ran: $sql\n";
                    }
                } finally {
                    echo "connection closed\n";
                }
            });
        }
        self::$queries->write($sql);
    }
}

phasync::run(function () {
    phasync::run(fn () => Connection::query('SELECT 1'));   // a request
    echo "first request done\n";
    phasync::run(fn () => Connection::query('SELECT 2'));   // another
    echo "second request done\n";
    phasync::sleep(0.05);
});
// run() cancelled the service once it had nothing else to do
echo "run() returned\n";
