<?php

use phasync\Util\Console;

putenv('COLUMNS=120');
echo Console::columns(), "\n";

putenv('COLUMNS');                     // unset: 80
echo Console::columns(), "\n";
