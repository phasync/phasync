<?php

/*
 * phasync with ext-parallel (#149): on a thread-safe (ZTS) PHP, each parallel\Runtime is a thread
 * with its own PHP state, so its own phasync loop. Runs only where parallel is loaded (CI's ZTS job).
 */

use parallel\Runtime;

beforeEach(function () {
    if (!\extension_loaded('parallel')) {
        $this->markTestSkipped('needs ext-parallel (a ZTS PHP)');
    }
});

$bootstrap = \dirname(__DIR__) . '/vendor/autoload.php';

test('a thread runs a phasync loop of its own, alongside the loop of the main thread', function () use ($bootstrap) {
    $runtime = new Runtime($bootstrap);
    $future  = $runtime->run(static function (): array {
        return phasync::run(static function () {
            $log = [];
            $a   = phasync::go(static function () use (&$log) {
                phasync::sleep(0.05);
                $log[] = 'a';
            });
            $b   = phasync::go(static function () use (&$log) {
                phasync::sleep(0.02);
                $log[] = 'b';
            });
            phasync::await($a);
            phasync::await($b);

            return $log;
        });
    });
    $main = phasync::run(static function () {
        phasync::sleep(0.03);

        return 'main';
    });
    expect($main)->toBe('main');
    expect($future->value())->toBe(['b', 'a']);
    $runtime->close();
});

test('threads run their loops at the same time: two threads sleeping 0.2 s take about 0.2 s', function () use ($bootstrap) {
    $task    = static fn (): float => phasync::run(static function () {
        $t = \microtime(true);
        phasync::sleep(0.2);

        return \microtime(true) - $t;
    });
    $start    = \microtime(true);
    $runtimes = [new Runtime($bootstrap), new Runtime($bootstrap)];
    $futures  = \array_map(static fn (Runtime $r) => $r->run($task), $runtimes);
    foreach ($futures as $future) {
        expect($future->value())->toBeGreaterThanOrEqual(0.199);
    }
    expect(\microtime(true) - $start)->toBeLessThan(0.35);
    foreach ($runtimes as $runtime) {
        $runtime->close();
    }
});

test('a coroutine waits for a thread\'s result without blocking the other coroutines, by checking done() between sleeps', function () use ($bootstrap) {
    $runtime = new Runtime($bootstrap);
    $log     = phasync::run(static function () use ($runtime) {
        $log    = [];
        $future = $runtime->run(static function (): string {
            \usleep(100000);

            return 'from the thread';
        });
        phasync::go(static function () use (&$log) {
            for ($i = 0; $i < 3; ++$i) {
                phasync::sleep(0.01);
                $log[] = "tick $i";
            }
        });
        while (!$future->done()) { // Future::value() would block the whole thread, every coroutine with it
            phasync::sleep(0.005);
        }
        $log[] = $future->value();

        return $log;
    });
    expect($log)->toBe(['tick 0', 'tick 1', 'tick 2', 'from the thread']);
    $runtime->close();
});

test('a phasync channel in one thread and a parallel\\Channel between threads work side by side', function () use ($bootstrap) {
    $runtime = new Runtime($bootstrap);
    $between = parallel\Channel::make('phasync-149-' . \getmypid(), parallel\Channel::Infinite);
    $future  = $runtime->run(static function (parallel\Channel $between): int {
        return phasync::run(static function () use ($between) {
            phasync::channel($read, $write, 4);
            phasync::go(static function () use ($write) {
                foreach ([1, 2, 3] as $n) {
                    $write->write($n);
                }
                $write->close();
            });
            $sum = 0;
            foreach ($read as $n) {
                $sum += $n;
                $between->send($n);
            }

            return $sum;
        });
    }, [$between]);
    expect($future->value())->toBe(6);
    expect([$between->recv(), $between->recv(), $between->recv()])->toBe([1, 2, 3]);
    $between->close();
    $runtime->close();
});
