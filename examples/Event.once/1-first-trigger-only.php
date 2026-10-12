<?php

use phasync\Util\Event;

$connected = new Event();
$connected->once(fn (string $host) => print("first connection, to $host: warm the cache\n"));
$connected->listen(fn (string $host) => print("connected to $host\n"));

$connected->trigger('db1');
$connected->trigger('db2');
var_dump($connected->hasListeners());   // the listen() one is still there
