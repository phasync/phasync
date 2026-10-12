<?php

use phasync\Util\Console;

$out = new Console(STDOUT, color: false);
foreach (['hello', '<!bold>hello<!>', 'héllo', '日本語', 'e' . "\u{301}"] as $text) {
    $out->write('<!pad 18>' . Console::escape($text) . '<!>strlen ' . strlen($text) . ', width ' . Console::width($text) . "\n");
}
