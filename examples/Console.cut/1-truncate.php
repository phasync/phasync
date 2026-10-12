<?php

use phasync\Util\Console;

var_dump(Console::cut('a very long file name.txt', 10, '…'));
var_dump(Console::cut('short', 10, '…'));                  // fits: unchanged
var_dump(Console::cut('<!bold>bold text here<!>', 6));    // the open span is closed
