<?php

use phasync\Util\Console;

$out = new Console(STDOUT, color: false);
$out->write("<!center 30>title<!>|\n");
$out->write("<!pad 30>status: <!green bold>ok<!> (3 checks)<!>|\n");
$out->write("<!pad 10 clip>clipped-without-ellipsis<!>|\n");

try {
    $out->write("<!pad>no width<!>\n");
} catch (InvalidArgumentException $e) {
    echo get_class($e), "\n";
}
