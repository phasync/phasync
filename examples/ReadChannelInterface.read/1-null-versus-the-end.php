<?php

phasync::run(function () {
    phasync::channel($read, $write, 3);
    phasync::go(function () use ($write) {
        $write->write('a');
        $write->write(null);   // null is a value like any other
        $write->close();
    });

    phasync::go(function () use ($read) {
        while (true) {
            $value = $read->read(eof: $eof);
            if ($eof) {
                echo "end of channel\n";
                break;
            }
            echo 'value: ', var_export($value, true), "\n";
        }
    });
});
