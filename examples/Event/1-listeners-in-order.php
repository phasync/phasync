<?php

use phasync\Util\Event;

$onSave = new Event();
$onSave->listen(function (string $file) {
    echo "write $file to the search index\n";
});
$onSave->listen(function (string $file) {
    echo "purge $file from the cache\n";
});
$onSave->once(function (string $file) {
    echo "first save since start: $file\n";
});

$onSave->trigger('report.pdf');
echo "--\n";
$onSave->trigger('invoice.pdf');
