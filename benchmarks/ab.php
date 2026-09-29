<?php

/*
 * A quick A/B benchmark: the working tree against a git ref, in about half a minute.
 *
 *   php benchmarks/ab.php                 # working tree vs HEAD
 *   php benchmarks/ab.php v2.0.0-alpha19  # vs a tag or commit
 *   php benchmarks/ab.php HEAD --rounds=9 --only=switch,flag
 *
 * Each scenario runs for a fixed time in a fresh process, the two sides alternating round by
 * round, so background load hits both alike. It prints the median ops/s of each side and their
 * ratio (above 1: the working tree is faster). PHP options given with -d are passed on.
 * For the full set of scenarios and saved baselines, use bench.php.
 */

const SECONDS = 0.4; // measured time per scenario and run, after a short warm-up

$scenarios = [
    'switch_sleep0'   => 'two coroutines looping sleep(0)',
    'flag_roundtrip'  => 'raiseFlag()/awaitFlag() ping-pong',
    'go_await'        => 'go() + await() of a trivial closure',
    'waiters_10k'     => 'sleep(0) loop while 10 000 coroutines wait on a flag',
    'waiters_10k_tmo' => 'the same, the waiters with a 60 s timeout',
    'timers_100'      => '100 coroutines looping sleep(0.001)',
    'stream_pair'     => 'socketpair ping-pong, 64 byte messages',
];

if (isset($argv[1]) && \str_starts_with($argv[1], '--child=')) {
    child($scenarios, \substr($argv[1], 8), $argv[2]);
    exit;
}

$ref    = 'HEAD';
$rounds = 5;
$only   = null;
foreach (\array_slice($argv, 1) as $arg) {
    if (\str_starts_with($arg, '--rounds=')) {
        $rounds = (int) \substr($arg, 9);
    } elseif (\str_starts_with($arg, '--only=')) {
        $only = \explode(',', \substr($arg, 7));
    } else {
        $ref = $arg;
    }
}
$root = \dirname(__DIR__);
$tree = \sys_get_temp_dir() . '/phasync-ab-' . \getmypid();
\exec('git -C ' . \escapeshellarg($root) . ' worktree add --detach -q ' . \escapeshellarg($tree) . ' ' . \escapeshellarg($ref) . ' 2>&1', $out, $exit);
if (0 !== $exit) {
    \fwrite(\STDERR, \implode("\n", $out) . "\n");
    exit(1);
}
try {
    $php = [\PHP_BINARY, ...iniOptions()];
    \printf("A: %s   B: working tree   %d rounds, %.1f s per run   %s\n\n", $ref, $rounds, SECONDS, \PHP_VERSION);
    \printf("%-17s %12s %12s %7s\n", 'scenario', 'A ops/s', 'B ops/s', 'B/A');
    foreach ($scenarios as $name => $what) {
        if (null !== $only && !\array_filter($only, fn ($o) => \str_contains($name, $o))) {
            continue;
        }
        $a = $b = [];
        for ($i = 0; $i < $rounds; ++$i) {
            $a[] = run($php, $tree, $name);
            $b[] = run($php, $root, $name);
        }
        [$ma, $mb] = [median($a), median($b)];
        \printf("%-17s %12s %12s %6.2fx\n", $name, \number_format($ma), \number_format($mb), $ma > 0 ? $mb / $ma : 0);
    }
} finally {
    \exec('git -C ' . \escapeshellarg($root) . ' worktree remove --force ' . \escapeshellarg($tree));
}

function run(array $php, string $root, string $name): float
{
    $output = \shell_exec(\implode(' ', \array_map('escapeshellarg', [...$php, __FILE__, "--child=$name", $root])));

    return (float) $output;
}

function median(array $values): float
{
    \sort($values);

    return $values[\intdiv(\count($values), 2)];
}

/** The -d options this PHP was started with. */
function iniOptions(): array
{
    $args    = \explode("\0", \rtrim((string) @\file_get_contents('/proc/self/cmdline'), "\0"));
    $options = [];
    for ($i = 1; $i < \count($args) && $args[$i] !== $_SERVER['argv'][0]; ++$i) {
        if ('-d' === $args[$i]) {
            \array_push($options, '-d', $args[++$i]);
        } elseif (\str_starts_with($args[$i], '-d')) {
            $options[] = $args[$i];
        }
    }

    return $options;
}

/** Child: load phasync from $root, run one scenario, print its ops/s. */
function child(array $scenarios, string $name, string $root): void
{
    // phasync from $root only (no Composer autoloader: it would load this checkout's copy)
    \spl_autoload_register(static function (string $class) use ($root) {
        if (\str_starts_with($class, 'phasync\\') && \is_file($f = $root . '/src/' . \str_replace('\\', '/', \substr($class, 8)) . '.php')) {
            require $f;
        } elseif ('phasync' === $class) {
            require $root . '/phasync.php';
        }
    });
    require_once $root . '/src/functions.php';
    require_once $root . '/src/ext.php';

    $measure = static function (Closure $round) {
        $round(0.05); // warm-up
        $t = \hrtime(true);
        $ops = $round(SECONDS);

        return $ops / ((\hrtime(true) - $t) / 1e9);
    };
    $loop = static function (float $seconds, Closure $step): int {
        $n   = 0;
        $end = \microtime(true) + $seconds;
        while (\microtime(true) < $end) {
            for ($i = 0; $i < 100; ++$i) {
                $step();
            }
            $n += 100;
        }

        return $n;
    };
    $waiters = static function (bool $timeout) use ($loop) {
        return static function (float $seconds) use ($loop, $timeout) {
            return phasync::run(static function () use ($loop, $seconds, $timeout) {
                $flag = new stdClass();
                for ($i = 0; $i < 10000; ++$i) {
                    phasync::go(static function () use ($flag, $timeout) {
                        try {
                            phasync::awaitFlag($flag, $timeout ? 60 : \PHP_FLOAT_MAX);
                        } catch (Throwable) {
                        }
                    });
                }
                $n = $loop($seconds, static fn () => phasync::sleep(0));
                phasync::raiseFlag($flag);

                return $n;
            });
        };
    };

    $rounds = [
        'switch_sleep0' => static fn (float $seconds) => phasync::run(static function () use ($loop, $seconds) {
            $other = phasync::go(static function () use ($seconds) {
                $end = \microtime(true) + $seconds;
                while (\microtime(true) < $end) {
                    phasync::sleep(0);
                }
            });
            $n = 2 * $loop($seconds, static fn () => phasync::sleep(0));
            phasync::await($other);

            return $n;
        }),
        'flag_roundtrip' => static fn (float $seconds) => phasync::run(static function () use ($seconds) {
            $ping = new stdClass();
            $pong = new stdClass();
            $stop = false;
            $peer = phasync::go(static function () use ($ping, $pong, &$stop) {
                while (!$stop) {
                    phasync::awaitFlag($ping);
                    phasync::raiseFlag($pong);
                }
            });
            $n   = 0;
            $end = \microtime(true) + $seconds;
            while (\microtime(true) < $end) {
                phasync::go(static fn () => null); // lets $peer reach its wait first
                phasync::raiseFlag($ping);
                phasync::awaitFlag($pong);
                ++$n;
            }
            $stop = true;
            phasync::raiseFlag($ping);
            phasync::await($peer);

            return $n;
        }),
        'go_await' => static fn (float $seconds) => phasync::run(static fn () => $loop($seconds, static fn () => phasync::await(phasync::go(static fn () => 1)))),
        'waiters_10k'     => $waiters(false),
        'waiters_10k_tmo' => $waiters(true),
        'timers_100' => static fn (float $seconds) => phasync::run(static function () use ($seconds) {
            $n      = 0;
            $end    = \microtime(true) + $seconds;
            $timers = [];
            for ($i = 0; $i < 100; ++$i) {
                $timers[] = phasync::go(static function () use (&$n, $end) {
                    while (\microtime(true) < $end) {
                        phasync::sleep(0.001);
                        ++$n;
                    }
                });
            }
            foreach ($timers as $timer) {
                phasync::await($timer);
            }

            return $n;
        }),
        'stream_pair' => static fn (float $seconds) => phasync::run(static function () use ($seconds) {
            [$a, $b] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
            \stream_set_blocking($a, false);
            \stream_set_blocking($b, false);
            $message = \str_repeat('x', 64);
            $stop    = false;
            $echo    = phasync::go(static function () use ($b, &$stop) {
                while (!$stop) {
                    phasync::readable($b);
                    $data = \fread($b, 65536);
                    if ('' !== $data && false !== $data) {
                        \fwrite($b, $data);
                    }
                }
            });
            $n   = 0;
            $end = \microtime(true) + $seconds;
            while (\microtime(true) < $end) {
                \fwrite($a, $message);
                phasync::readable($a);
                \fread($a, 65536);
                ++$n;
            }
            $stop = true;
            \fwrite($a, 'x');
            phasync::await($echo);

            return $n;
        }),
    ];

    echo $measure($rounds[$name]);
}
