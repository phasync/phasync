<?php

test('exception thrown directly in run', function() {
    expect(function() {
        phasync::run(function() {
            throw new Exception("Yes");
        });
    })->toThrow(new Exception("Yes"));
});

test('exception thrown directly in run after sleep', function() {
    expect(function() {        
        phasync::run(function() {
            phasync::sleep(0.1);
            throw new Exception("Yes");
        });
    })->toThrow(new Exception("Yes"));
});

test('exception thrown inside 1 deep coroutine', function() {
    expect(function() {
        phasync::run(function() {
            phasync::go(function() {
                throw new Exception("Exception inside coroutine");
            });
        });
    })->toThrow(new Exception("Exception inside coroutine"));
});

test('exception thrown inside 1 deep coroutine after sleep', function() {
    expect(function() {
        phasync::run(function() {
            phasync::go(function() {
                phasync::sleep(0.1);
                throw new Exception("Exception inside coroutine");
            });
        });
    })->toThrow(new Exception("Exception inside coroutine"));
});

test('exception thrown inside 2 deep coroutine', function() {
    expect(function() {
        phasync::run(function() {
            phasync::go(function() {
                phasync::go(function() {
                    throw new Exception("Nested exception");
                });
            });
        });
    })->toThrow(new Exception("Nested exception"));
});

test('exception thrown inside 2 deep coroutine after sleep in first', function() {
    expect(function() {
        phasync::run(function() {
            phasync::go(function() {
                phasync::sleep(0.1);
                phasync::go(function() {
                    throw new Exception("Nested exception");
                });
            });
        });
    })->toThrow(new Exception("Nested exception"));
});

test('exception thrown inside 2 deep coroutine after sleep in second', function() {
    expect(function() {
        phasync::run(function() {
            phasync::go(function() {
                phasync::go(function() {
                    phasync::sleep(0.1);
                    throw new Exception("Nested exception");
                });
            });
        });
    })->toThrow(new Exception("Nested exception"));
});

test('exception thrown inside 1 deep coroutine that is awaited', function() {
    phasync::run(function() {
        $future = phasync::go(function() {
            throw new Exception("Exception in awaited coroutine");
        });
        expect(function() use ($future) {
            phasync::await($future);
        })->toThrow(new Exception("Exception in awaited coroutine"));
    });
});

test('exception thrown inside 1 deep coroutine that is awaited and sleeping', function() {
    phasync::run(function() {
        $future = phasync::go(function() {
            phasync::sleep(0.1);
            throw new Exception("Exception in awaited coroutine");
        });
        expect(function() use ($future) {
            phasync::await($future);
        })->toThrow(new Exception("Exception in awaited coroutine"));
    });
});


test('exception thrown inside 1 deep coroutine that is awaited 2 times', function() {
    phasync::run(function() {
        $future = phasync::go(function() {
            throw new Exception("Exception in awaited coroutine");
        });
        expect(function() use ($future) {
            phasync::await($future);
        })->toThrow(new Exception("Exception in awaited coroutine"));
        expect(function() use ($future) {
            phasync::await($future);
        })->toThrow(new Exception("Exception in awaited coroutine"));
    });
});
