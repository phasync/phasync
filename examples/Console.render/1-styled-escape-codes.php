<?php

use phasync\Util\Console;

$styled = new Console(STDOUT, color: true);
$plain  = new Console(STDOUT, color: false);

// render() returns what write() would write: here made visible with json_encode()
echo json_encode($styled->render('<!red bold>failed<!>')), "\n";
echo json_encode($plain->render('<!red bold>failed<!>')), "\n";
