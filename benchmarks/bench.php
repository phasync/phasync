<?php

declare(strict_types=1);

/**
 * phasync micro benchmarks.
 *
 *   php benchmarks/bench.php                      run everything, print a table
 *   php benchmarks/bench.php --save=FILE           also write JSON (a baseline)
 *   php benchmarks/bench.php --compare=FILE        compare against a saved baseline
 *   php benchmarks/bench.php --compare=FILE --strict   exit 1 if anything is SLOWER
 *   php benchmarks/bench.php --only=chan,waitgroup only scenarios whose name contains one of these
 *   php benchmarks/bench.php --runs=N              measured runs per scenario (default 5)
 *
 * Every scenario runs in its own fresh PHP process (`--child=NAME`), so GC state, error
 * handlers and phasync's global driver never leak from one scenario to the next.
 * See benchmarks/README.md for the noise caveats.
 */

const SLOWER_RATIO = 0.85;
const FASTER_RATIO = 1.15;
const CHILD_TIMEOUT = 180.0;

/**
 * Each scenario: name => [description, callable(float $scale)]. The scale shrinks the operation count for the warm-up run.
 * The callable returns the number of operations performed, or [ops, seconds] when it wants
 * to time only an inner section (setup excluded). It may put figures in $GLOBALS['extra'].
 */
function scenarios(): array
{
    $n = static fn (int $base, float $scale): int => \max(1, (int) ($base * $scale));

    /** Create a channel from a finished coroutine, so the "creator fiber" heuristic never applies. */
    $channel = static function (int $capacity): array {
        $r = $w = null;
        \phasync::await(\phasync::go(static function () use (&$r, &$w, $capacity) {
            \phasync::channel($r, $w, $capacity);
        }));

        return [$r, $w];
    };

    return [
        'run_empty' => ['empty phasync::run() calls', static function (float $s) use ($n) {
            $count = $n(20000, $s);
            for ($i = 0; $i < $count; ++$i) {
                \phasync::run(static fn () => null);
            }

            return $count;
        }],

        'go_await_trivial' => ['go() + await() of a trivial closure', static function (float $s) use ($n) {
            $count = $n(40000, $s);
            \phasync::run(static function () use ($count) {
                for ($i = 0; $i < $count; ++$i) {
                    \phasync::await(\phasync::go(static fn () => $i));
                }
            });

            return $count;
        }],

        'go_alive_10k' => ['10k coroutines alive at once, each sleep(0) once', static function (float $s) use ($n) {
            $reps = $n(3, $s);
            for ($r = 0; $r < $reps; ++$r) {
                \phasync::run(static function () {
                    $fibers = [];
                    for ($i = 0; $i < 10000; ++$i) {
                        $fibers[] = \phasync::go(static function () {
                            \phasync::sleep(0);

                            return 1;
                        });
                    }
                    foreach ($fibers as $f) {
                        \phasync::await($f);
                    }
                });
            }

            return $reps * 10000;
        }],

        'switch_sleep0' => ['context switches: two coroutines looping sleep(0)', static function (float $s) use ($n) {
            $loops = $n(150000, $s);
            \phasync::run(static function () use ($loops) {
                $body = static function () use ($loops) {
                    for ($i = 0; $i < $loops; ++$i) {
                        \phasync::sleep(0);
                    }
                };
                $a = \phasync::go($body);
                $b = \phasync::go($body);
                \phasync::await($a);
                \phasync::await($b);
            });

            return 2 * $loops;
        }],

        'switch_yield' => ['context switches: two coroutines looping yield()', static function (float $s) use ($n) {
            $loops = $n(100000, $s);
            \phasync::run(static function () use ($loops) {
                $body = static function () use ($loops) {
                    for ($i = 0; $i < $loops; ++$i) {
                        \phasync::yield();
                    }
                };
                $a = \phasync::go($body);
                $b = \phasync::go($body);
                \phasync::await($a);
                \phasync::await($b);
            });

            return 2 * $loops;
        }],

        'flag_roundtrip' => ['raiseFlag()/awaitFlag() ping-pong round trips', static function (float $s) use ($n) {
            $count = $n(100000, $s);
            \phasync::run(static function () use ($count) {
                $f1 = new stdClass();
                $f2 = new stdClass();
                $a = \phasync::go(static function () use ($f1, $f2, $count) {
                    for ($i = 0; $i < $count; ++$i) {
                        \phasync::awaitFlag($f1);
                        \phasync::raiseFlag($f2);
                    }
                });
                $b = \phasync::go(static function () use ($f1, $f2, $count) {
                    for ($i = 0; $i < $count; ++$i) {
                        \phasync::raiseFlag($f1);
                        \phasync::awaitFlag($f2);
                    }
                });
                \phasync::await($a);
                \phasync::await($b);
            });

            return $count;
        }],

        'chan_unbuffered_pingpong' => ['unbuffered channels, request/response round trips', static function (float $s) use ($n, $channel) {
            $count = $n(40000, $s);
            \phasync::run(static function () use ($count, $channel) {
                [$reqR, $reqW] = $channel(0);
                [$respR, $respW] = $channel(0);
                $server = \phasync::go(static function () use ($reqR, $respW) {
                    while (null !== ($v = $reqR->read())) {
                        $respW->write($v);
                    }
                    $respW->close();
                });
                $client = \phasync::go(static function () use ($reqW, $respR, $count) {
                    for ($i = 0; $i < $count; ++$i) {
                        $reqW->write($i);
                        $respR->read();
                    }
                    $reqW->close();
                });
                \phasync::await($client);
                \phasync::await($server);
            });

            return $count;
        }],

        'chan_buffered64' => ['buffered channel (capacity 64), producer/consumer messages', static function (float $s) use ($n, $channel) {
            $count = $n(150000, $s);
            \phasync::run(static function () use ($count, $channel) {
                [$r, $w] = $channel(64);
                $producer = \phasync::go(static function () use ($w, $count) {
                    for ($i = 0; $i < $count; ++$i) {
                        $w->write($i);
                    }
                    $w->close();
                });
                $consumer = \phasync::go(static function () use ($r) {
                    $got = 0;
                    while (null !== $r->read()) {
                        ++$got;
                    }

                    return $got;
                });
                \phasync::await($producer);
                \phasync::await($consumer);
            });

            return $count;
        }],

        'waitgroup_1000' => ['WaitGroup with 1000 workers (sleep(0) then done)', static function (float $s) use ($n) {
            $reps = $n(15, $s);
            for ($r = 0; $r < $reps; ++$r) {
                \phasync::run(static function () {
                    $wg = \phasync::waitGroup();
                    for ($i = 0; $i < 1000; ++$i) {
                        $wg->add();
                        \phasync::go(static function () use ($wg) {
                            \phasync::sleep(0);
                            $wg->done();
                        });
                    }
                    $wg->await();
                });
            }

            return $reps * 1000;
        }],

        'timers_1000' => ['timer wakeups: 1000 coroutines looping sleep(0.001)', static function (float $s) use ($n) {
            $loops = $n(100, $s);
            \phasync::run(static function () use ($loops) {
                $fibers = [];
                for ($c = 0; $c < 1000; ++$c) {
                    $fibers[] = \phasync::go(static function () use ($loops) {
                        for ($i = 0; $i < $loops; ++$i) {
                            \phasync::sleep(0.001);
                        }
                    });
                }
                foreach ($fibers as $f) {
                    \phasync::await($f);
                }
            });

            return 1000 * $loops;
        }],

        'stream_pair_4k' => ['socketpair echo, 4 KB messages, round trips', static function (float $s) use ($n) {
            $count = $n(10000, $s);
            [$a, $b] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
            \stream_set_blocking($a, false);
            \stream_set_blocking($b, false);
            $message = \str_repeat('m', 4096);
            $readAll = static function ($fp): string {
                $buf = '';
                while (\strlen($buf) < 4096) {
                    \phasync::readable($fp);
                    $buf .= \fread($fp, 4096 - \strlen($buf));
                }

                return $buf;
            };
            \phasync::run(static function () use ($a, $b, $count, $message, $readAll) {
                $server = \phasync::go(static function () use ($b, $count, $readAll) {
                    for ($i = 0; $i < $count; ++$i) {
                        $buf = $readAll($b);
                        \phasync::writable($b);
                        \fwrite($b, $buf);
                    }
                });
                $client = \phasync::go(static function () use ($a, $count, $message, $readAll) {
                    for ($i = 0; $i < $count; ++$i) {
                        \phasync::writable($a);
                        \fwrite($a, $message);
                        $readAll($a);
                    }
                });
                \phasync::await($client);
                \phasync::await($server);
            });
            \fclose($a);
            \fclose($b);

            return $count;
        }],

        'sbuf_frames64' => ['StringBuffer: write 64-byte frames, readFixed(64)', static function (float $s) use ($n) {
            $count = $n(600000, $s);
            \phasync::run(static function () use ($count) {
                $sb = new phasync\Util\StringBuffer();
                $frame = \str_repeat('f', 64);
                $producer = \phasync::go(static function () use ($sb, $frame, $count) {
                    for ($i = 0; $i < $count; ++$i) {
                        $sb->write($frame);
                        if (63 === ($i & 63)) {
                            \phasync::sleep(0);
                        }
                    }
                    $sb->end();
                });
                $consumer = \phasync::go(static function () use ($sb, $count) {
                    for ($i = 0; $i < $count; ++$i) {
                        $sb->readFixed(64);
                    }
                });
                \phasync::await($consumer);
                \phasync::await($producer);
            });

            return $count;
        }],

        'sbuf_chunks8k_read100' => ['StringBuffer: write 8 KB chunks, read(100) calls', static function (float $s) use ($n) {
            $chunks = $n(12000, $s);
            $reads = 0;
            \phasync::run(static function () use ($chunks, &$reads) {
                $sb = new phasync\Util\StringBuffer();
                $chunk = \str_repeat('c', 8192);
                $producer = \phasync::go(static function () use ($sb, $chunk, $chunks) {
                    for ($i = 0; $i < $chunks; ++$i) {
                        $sb->write($chunk);
                        \phasync::sleep(0);
                    }
                    $sb->end();
                });
                $consumer = \phasync::go(static function () use ($sb, &$reads) {
                    while ('' !== $sb->read(100)) {
                        ++$reads;
                    }
                });
                \phasync::await($consumer);
                \phasync::await($producer);
            });

            return $reads;
        }],

        'sbuf_frame_parse' => ['StringBuffer: parse length-prefixed frames from 8 KB chunks', static function (float $s) use ($n) {
            // Setup (not timed): deterministic frames of 10..200 bytes with a 4-byte length prefix.
            \mt_srand(1234);
            $stream = '';
            $frames = 0;
            while (\strlen($stream) < 1000000) {
                $len = \mt_rand(10, 200);
                $stream .= \pack('N', $len) . \str_repeat('p', $len);
                ++$frames;
            }
            $chunks = \str_split($stream, 8192);
            $reps = $n(50, $s);
            $parsed = 0;
            $start = \hrtime(true);
            for ($r = 0; $r < $reps; ++$r) {
                \phasync::run(static function () use ($chunks, &$parsed) {
                    $sb = new phasync\Util\StringBuffer();
                    $producer = \phasync::go(static function () use ($sb, $chunks) {
                        foreach ($chunks as $chunk) {
                            $sb->write($chunk);
                            \phasync::sleep(0);
                        }
                        $sb->end();
                    });
                    $consumer = \phasync::go(static function () use ($sb, &$parsed) {
                        while (null !== ($header = $sb->readFixed(4))) {
                            $len = \unpack('N', $header)[1];
                            $sb->readFixed($len);
                            ++$parsed;
                        }
                    });
                    \phasync::await($consumer);
                    \phasync::await($producer);
                });
            }

            return [$parsed, (\hrtime(true) - $start) / 1e9];
        }],

        'memory_100k' => ['100k short coroutines created and finished (peak memory, GC runs)', static function (float $s) use ($n) {
            $batches = $n(100, $s);
            $gc0 = \gc_status();
            $mem0 = \memory_get_usage();
            \phasync::run(static function () use ($batches) {
                for ($b = 0; $b < $batches; ++$b) {
                    $fibers = [];
                    for ($i = 0; $i < 1000; ++$i) {
                        $fibers[] = \phasync::go(static fn () => $i);
                    }
                    foreach ($fibers as $f) {
                        \phasync::await($f);
                    }
                }
            });
            $gc1 = \gc_status();
            $GLOBALS['extra'] = [
                'peak_memory_mb'   => \round(\memory_get_peak_usage() / 1048576, 2),
                'memory_growth_mb' => \round((\memory_get_usage() - $mem0) / 1048576, 2),
                'gc_runs'          => $gc1['runs'] - $gc0['runs'],
                'gc_collected'     => $gc1['collected'] - $gc0['collected'],
            ];

            return $batches * 1000;
        }],
    ];
}

function median(array $values): float
{
    \sort($values);
    $c = \count($values);

    return $c % 2 ? (float) $values[intdiv($c, 2)] : ($values[$c / 2 - 1] + $values[$c / 2]) / 2;
}

function optionValue(array $argv, string $name): ?string
{
    foreach ($argv as $arg) {
        if (\str_starts_with($arg, "--$name=")) {
            return \substr($arg, \strlen($name) + 3);
        }
    }

    return null;
}

/**
 * Child mode: run one scenario (one warm-up at 10% size, then N measured runs) and print JSON.
 */
function runChild(string $name, int $runs): void
{
    \error_reporting(\E_ALL & ~\E_DEPRECATED);
    \ini_set('display_errors', 'stderr');
    \ini_set('memory_limit', '-1');
    require __DIR__ . '/../vendor/autoload.php';

    $scenarios = scenarios();
    if (!isset($scenarios[$name])) {
        \fwrite(\STDERR, "Unknown scenario $name\n");
        exit(2);
    }
    [, $fn] = $scenarios[$name];

    $fn(0.1);

    $results = [];
    for ($i = 0; $i < $runs; ++$i) {
        $GLOBALS['extra'] = [];
        $start = \hrtime(true);
        $out = $fn(1.0);
        $elapsed = (\hrtime(true) - $start) / 1e9;
        [$ops, $seconds] = \is_array($out) ? $out : [$out, $elapsed];
        $results[] = ['ops' => $ops, 'seconds' => $seconds, 'extra' => $GLOBALS['extra']];
    }
    echo \json_encode($results), "\n";
}

/**
 * Parent mode: run a scenario in a fresh process and return its decoded results (or an error string).
 */
function spawn(string $name, int $runs): array|string
{
    $stderrFile = \tempnam(\sys_get_temp_dir(), 'bench');
    $proc = \proc_open(
        [\PHP_BINARY, __FILE__, "--child=$name", "--runs=$runs"],
        [1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']],
        $pipes
    );
    if (!\is_resource($proc)) {
        return 'could not start child process';
    }
    $stdout = '';
    $deadline = \microtime(true) + CHILD_TIMEOUT;
    \stream_set_blocking($pipes[1], false);
    while (!\feof($pipes[1])) {
        $r = [$pipes[1]];
        $w = $e = null;
        if (\microtime(true) > $deadline) {
            \proc_terminate($proc, 9);
            \proc_close($proc);
            @\unlink($stderrFile);

            return 'timed out after ' . CHILD_TIMEOUT . ' s';
        }
        if (false !== \stream_select($r, $w, $e, 1)) {
            $stdout .= \stream_get_contents($pipes[1]);
        }
    }
    $exit = \proc_close($proc);
    $stderr = \trim((string) \file_get_contents($stderrFile));
    @\unlink($stderrFile);

    $decoded = \json_decode(\trim($stdout), true);
    if (0 !== $exit || !\is_array($decoded)) {
        return 'exit ' . $exit . ($stderr !== '' ? ': ' . \substr($stderr, 0, 400) : '');
    }

    return $decoded;
}

function fmt(float $v): string
{
    return \number_format($v, 0, '.', ' ');
}

// ---------------------------------------------------------------------------------------

$argv = $_SERVER['argv'];
if (null !== ($child = optionValue($argv, 'child'))) {
    runChild($child, (int) (optionValue($argv, 'runs') ?? 5));
    exit(0);
}

$runs = \max(1, (int) (optionValue($argv, 'runs') ?? 5));
$only = null !== ($o = optionValue($argv, 'only')) ? \explode(',', $o) : null;
$save = optionValue($argv, 'save');
$compareFile = optionValue($argv, 'compare');
$strict = \in_array('--strict', $argv, true);

$baseline = null;
if (null !== $compareFile) {
    $baseline = \json_decode((string) @\file_get_contents($compareFile), true);
    if (!\is_array($baseline) || !isset($baseline['scenarios'])) {
        \fwrite(\STDERR, "Can't read baseline $compareFile\n");
        exit(2);
    }
}

$cpu = 'unknown';
if (\is_readable('/proc/cpuinfo') && \preg_match('/^model name\s*:\s*(.+)$/m', (string) \file_get_contents('/proc/cpuinfo'), $m)) {
    $cpu = \trim($m[1]);
}
$git = \trim((string) @\shell_exec('git -C ' . \escapeshellarg(__DIR__ . '/..') . ' rev-parse HEAD 2>/dev/null'));
$meta = [
    'php'  => \PHP_VERSION,
    'os'   => \php_uname('s') . ' ' . \php_uname('r'),
    'cpu'  => $cpu,
    'date' => \date('c'),
    'git'  => $git ?: null,
    'runs' => $runs,
];

$started = \microtime(true);
$report = [];
$errors = [];
foreach (scenarios() as $name => [$description]) {
    if (null !== $only && [] === \array_filter($only, static fn ($o) => '' !== $o && \str_contains($name, $o))) {
        continue;
    }
    \fwrite(\STDERR, \sprintf("running %-26s\r", $name));
    $result = spawn($name, $runs);
    if (\is_string($result)) {
        $errors[$name] = $result;
        continue;
    }
    $perSecond = \array_map(static fn ($r) => $r['ops'] / \max($r['seconds'], 1e-9), $result);
    $report[$name] = [
        'description' => $description,
        'ops'         => $result[0]['ops'],
        'median_ops'  => median($perSecond),
        'min_ops'     => \min($perSecond),
        'max_ops'     => \max($perSecond),
        'median_secs' => median(\array_column($result, 'seconds')),
        'extra'       => \end($result)['extra'],
    ];
}
\fwrite(\STDERR, \str_repeat(' ', 40) . "\r");

echo \sprintf("phasync benchmarks, PHP %s, %s, %d run(s) per scenario\n", $meta['php'], $cpu, $runs);
if (null !== $baseline && ($baseline['meta']['cpu'] ?? null) !== $cpu) {
    echo "WARNING: baseline was recorded on a different CPU (" . ($baseline['meta']['cpu'] ?? '?') . "); ratios are not meaningful.\n";
}
echo \sprintf("\n%-26s %13s %13s %13s %7s", 'scenario', 'median ops/s', 'min', 'max', 'spread');
echo null !== $baseline ? \sprintf(" %13s %7s\n", 'baseline', 'ratio') : "\n";
$slower = 0;
foreach ($report as $name => $r) {
    $spread = $r['median_ops'] > 0 ? ($r['max_ops'] - $r['min_ops']) / $r['median_ops'] * 100 : 0;
    echo \sprintf('%-26s %13s %13s %13s %6.1f%%', $name, fmt($r['median_ops']), fmt($r['min_ops']), fmt($r['max_ops']), $spread);
    if (null !== $baseline) {
        $b = $baseline['scenarios'][$name]['median_ops'] ?? null;
        if (null === $b) {
            echo '           (new)';
        } else {
            $ratio = $r['median_ops'] / $b;
            $flag = $ratio < SLOWER_RATIO ? '  SLOWER' : ($ratio > FASTER_RATIO ? '  FASTER' : '');
            $slower += '  SLOWER' === $flag ? 1 : 0;
            echo \sprintf(' %13s %6.2fx%s', fmt($b), $ratio, $flag);
        }
    }
    echo "\n";
}
foreach ($report as $name => $r) {
    if ([] !== $r['extra']) {
        echo "  $name: " . \json_encode($r['extra']) . "\n";
    }
}
foreach ($errors as $name => $error) {
    echo "ERROR $name: $error\n";
}
if (null !== $baseline) {
    foreach (\array_diff_key($baseline['scenarios'], $report) as $name => $_) {
        if (null === $only && !isset($errors[$name])) {
            echo "MISSING $name (in baseline, not run)\n";
        }
    }
}
echo \sprintf("\ntotal %.1f s\n", \microtime(true) - $started);

if (null !== $save) {
    \file_put_contents($save, \json_encode(['meta' => $meta, 'scenarios' => $report], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
    echo "saved $save\n";
}

exit($strict && ($slower > 0 || [] !== $errors) ? 1 : 0);
