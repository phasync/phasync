<?php

use phasync\ChannelException;

phasync::setDefaultTimeout(1);

test('basic deadlock protection in phasync', function () {
    expect(function () {
        phasync::run(function () {
            phasync::channel($reader, $writer);

            // Should throw exception warning about deadlock
            $reader->read();
        });
    })->toThrow(ChannelException::class);
});

test('reader in child coroutine, writer in main coroutine', function () {
    expect(phasync::run(function () {
        phasync::channel($reader, $writer);

        phasync::go(function () use ($reader) {
            $reader->read();
        });

        $writer->write('dummy');

        return 1;
    }))->toBe(1);
});

test('reader in child coroutine, never written', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);

        phasync::go(function () use ($reader) {
            expect($reader->read())->toBe(null);
        });

        return 1;
    });
});

test('writer in child coroutine, reader in main coroutine', function () {
    expect(phasync::run(function () {
        phasync::channel($reader, $writer);

        phasync::go(function () use ($writer) {
            $writer->write('dummy');
        });

        return $reader->read();
    }))->toBe('dummy');
});

test('writer in child coroutine, never read', function () {
    expect(function () {
        phasync::run(function () {
            phasync::channel($reader, $writer);

            phasync::go(function () use ($writer) {
                $writer->write('dummy');
            });

            return 1;
        });
    })->toThrow(ChannelException::class);
});

test('reader and writer in child coroutines', function () {
    expect(phasync::run(function () {
        phasync::channel($reader, $writer);

        phasync::go(function () use ($writer) {
            $writer->write('dummy');
        });

        phasync::go(function () use ($reader) {
            $reader->read();
        });

        return 1;
    }))->toBe(1);
});

test('reader starts after writer is closed', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);

        $writer->close(); // Close the writer before the reader starts.

        phasync::go(function () use ($reader) {
            expect($reader->read())->toBeNull(); // Should return null immediately because the writer is closed.
        });
    });
});

test('immediate operation by creator throws exception', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);

        // Attempting the first operation immediately by the creator should throw.
        expect(function () use ($writer) {
            $writer->write('test');
        })->toThrow(ChannelException::class);
    });
});

test('first operation by another coroutine succeeds', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);

        // Delegate the first write to a child coroutine.
        phasync::go(function () use ($writer) {
            $writer->write('test');
        });

        // Read operation should succeed following a successful write.
        expect($reader->read())->toBe('test');
    });
});

test('multiple readers and writers from different coroutines', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);

        phasync::go(function () use ($writer) {
            $writer->write('message1');
        });

        phasync::go(function () use ($writer) {
            $writer->write('message2');
        });

        phasync::go(function () use ($reader) {
            expect($reader->read())->toBe('message1');
        });

        phasync::go(function () use ($reader) {
            expect($reader->read())->toBe('message2');
        });
    });
});

test('reader reacts correctly when writer closes before any read', function () {
    phasync::run(function () {
        phasync::channel($reader, $writer);

        phasync::go(function () use ($writer) {
            $writer->close(); // Close the writer in a different coroutine.
        });

        expect($reader->read())->toBeNull();  // Expect null if read from a closed channel.
    });
});

test('channels memory leaking', function () {
    \gc_collect_cycles();
    $memStart = \memory_get_usage(true);

    for ($i = 0; $i < 10000; ++$i) {
        phasync::run(function () {
            phasync::channel($read1, $write1);
            phasync::channel($read2, $write2);
            phasync::go(function () use ($read1, $write2) {
                while ($value = $read1->read()) {
                    $write2->write($value);
                }
            });
            phasync::go(function () use ($write1) {
                for ($i = 0; $i < 10; ++$i) {
                    $write1->write('dummy');
                }
            });
            phasync::go(function () use ($write1) {
                $write1->write('dummy');
            });
            phasync::go(function () use ($read2) {
                while ($value = $read2->read()) {
                }
            });
        });
    }
    expect($memStart)->toBeGreaterThan(\memory_get_usage(true) - 500000);
});

test('unbuffered channel tests', function () {
    phasync::run(function () {
        phasync::channel($r, $w, 0);
        phasync::go(function () use ($r) {
            expect($r->read())->toBe(1);
            expect($r->read())->toBeNull();
        });
        phasync::go(function () use ($w) {
            $w->write(1);
        });
    });
    phasync::run(function () {
        phasync::channel($r, $w, 0);
        phasync::go(function () use ($r) {
            expect($r->read())->toBe(1);
            expect($r->read())->toBeNull();
        });
        $w->write(1);
    });
    phasync::run(function () {
        phasync::channel($r, $w, 0);
        phasync::go(function () use ($r) {
            expect($r->read())->toBeNull();
        });
    });
});

test('buffered channel tests', function () {
    phasync::run(function () {
        phasync::channel($r, $w, 1);
        phasync::go(function () use ($r) {
            expect($r->read())->toBe(1);
            expect($r->read())->toBeNull();
        });
        phasync::go(function () use ($w) {
            $w->write(1);
        });
    });
    phasync::run(function () {
        phasync::channel($r, $w, 1);
        phasync::go(function () use ($r) {
            expect($r->read())->toBe(1);
            expect($r->read())->toBeNull();
        });
        $w->write(1);
    });
    phasync::run(function () {
        phasync::channel($r, $w, 1);
        phasync::go(function () use ($r) {
            expect($r->read())->toBeNull();
        });
    });
});
