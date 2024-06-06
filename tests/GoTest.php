<?php

test('phasync::go() with concurrent', function () {
    phasync::run(function () {
        $counter = 0;
        $result  = phasync::go(concurrent: 10, fn: function () use (&$counter) {
            if ($counter % 2) {
                phasync::sleep(0.1);
            }

            return $counter++;
        });

        expect(phasync::await($result))->toBe([0, 1, 2, 3, 4, 5, 6, 7, 8, 9]);

        expect($counter)->toBe(10);
    });
});
