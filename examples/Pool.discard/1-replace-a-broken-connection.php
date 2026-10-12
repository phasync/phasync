<?php

use phasync\Util\Pool;

phasync::run(function () {
    $made = 0;
    $pool = new Pool(
        create: function () use (&$made) {
            return (object) ['id' => ++$made];
        },
        size: 2,
        dispose: function (object $conn) {
            echo "closing connection {$conn->id}\n";
        },
    );

    $conn = $pool->borrow();
    echo "connection {$conn->id} lost its server\n";
    $pool->discard($conn);                 // the pool forgets it and passes it to dispose

    $pool->use(fn (object $conn) => print("next borrower gets connection {$conn->id}\n"));
});
