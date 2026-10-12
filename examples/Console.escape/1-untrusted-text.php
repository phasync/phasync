<?php

use phasync\Util\Console;

$out      = new Console(STDOUT, color: false);
$filename = 'report<!red>.pdf';        // from a user: it must not become a span

$out->write('<!bold>file:<!> ' . Console::escape($filename) . "\n");
$out->write('<!bold>file:<!> ' . $filename . "<!>\n");   // without escape() the markup is taken
