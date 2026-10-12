<?php

final class Connection
{
    private static ?phasync\WriteChannelInterface $queries = null;

    public static function query(string $sql): void
    {
        if (null === self::$queries) {
            phasync::channel($read, self::$queries, 10);
            // A service belongs to no context: it outlives the request that started it
            phasync::service(function () use ($read) {
                foreach ($read as $sql) {
                    phasync::sleep(0.01);
                    echo "ran: $sql\n";
                }
                echo "connection closed\n";
            });
        }
        self::$queries->write($sql);
    }

    public static function close(): void
    {
        self::$queries?->close();
    }
}

phasync::run(function () {
    phasync::withContext(fn () => Connection::query('SELECT 1'), new stdClass());
    echo "first request done\n";
    phasync::withContext(fn () => Connection::query('SELECT 2'), new stdClass());
    echo "second request done\n";
    Connection::close();
});
// run() returns after the service has ended
echo "run() returned\n";
