<?php

use function phasync\ext\ensure_loaded;

// Call it first thing: when the extension is not loaded, it restarts this CLI process with it
try {
    ensure_loaded();
    echo "the phasync extension is loaded\n";
} catch (RuntimeException $e) {
    echo "no extension for this PHP here; phasync runs without it\n";
}

echo phasync::run(fn () => 'phasync works either way'), "\n";
