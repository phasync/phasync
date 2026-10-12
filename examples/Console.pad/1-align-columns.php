<?php

use phasync\Util\Console;

$rows = [['名前', 'Tokyo', 13_960_000], ['name', 'Oslo', 709_000]];
foreach ($rows as [$a, $b, $n]) {
    echo Console::pad($a, 8), Console::pad($b, 8, STR_PAD_BOTH), Console::pad(number_format($n), 12, STR_PAD_LEFT), "|\n";
}
echo Console::pad('7', 3, STR_PAD_LEFT, '0'), "\n";
