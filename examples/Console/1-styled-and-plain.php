<?php

use phasync\Util\Console;

// Piped to a file there are no escape codes, and the layout stays the same
$out = new Console(STDOUT, color: false);
$out->write("<!bold>Options<!>\n");
$out->write("  <!green pad 12>--http<!><!dim>Serve HTTP on this address<!>\n");
$out->write("  <!green pad 12>--workers<!><!dim>Worker processes<!>\n");
$out->write("<!lpad 10>1.2 MB<!> <!pad 12>report.pdf<!>|\n");
$out->write("<!lpad 10>800 kB<!> <!pad 12>an-extremely-long-name.pdf<!>|\n");
