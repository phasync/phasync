<?php

use phasync\Util\Console;

$log = fopen('php://memory', 'w+');
$out = new Console($log, color: false);

$out->log('info', 'listening on {address}', ['address' => '127.0.0.1:8080']);
$out->log('warning', 'disk {path} is full', ['path' => '/var'], source: 'w3');
$out->log('error', "control characters are escaped: \e[2J");

rewind($log);
// The time at the start of each line differs per run: replaced here
echo preg_replace('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d\d/m', 'YYYY-MM-DD hh:mm:ss.cc', stream_get_contents($log));
