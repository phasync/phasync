<?php
// Usage: php benchmarks/run.php [--jit] [--steady] [--filter=name] [--runs=3]
// Runs each benchmark for each runtime, takes the median, prints a table and writes results/<date>-<host>.md
$opt = getopt('', ['jit', 'steady', 'filter::', 'runs::']);
$runs = (int) ($opt['runs'] ?? 3);
$jit = isset($opt['jit']);
$filter = $opt['filter'] ?? null;
$dir = __DIR__;
$php = [PHP_BINARY];
if ($jit) {
    array_push($php, '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=1255', '-d', 'opcache.jit_buffer_size=64M');
}
$runtimes = ['phasync', 'amp', 'react-fiber', 'react-callback'];
// bench => [kind, runtimes it has a script for]
$benches = [
    'spawn-trivial' => 'run', 'spawn-suspend-once' => 'run', 'sleepers-10k' => 'run',
    'tcp-echo' => 'tcp', 'http-fanout' => 'http',
];
if (isset($opt['steady'])) {
    $benches['churn'] = 'churn';
}

function run(array $cmd, ?int $timeout = 600): string
{
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    stream_set_blocking($pipes[1], true);
    $out = stream_get_contents($pipes[1]);
    proc_close($p);
    return trim($out);
}
function lastJson(string $out): ?array
{
    $lines = array_filter(explode("\n", $out));
    return $lines ? json_decode(end($lines), true) : null;
}
function median(array $v): float
{
    sort($v);
    return $v[intdiv(count($v), 2)];
}
/** Start a server process, wait for readiness. */
function serve(array $cmd, ?string $ready): array
{
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if ($ready !== null) {
        stream_get_line($pipes[1], 1024, "\n");
    } else {
        usleep(1500000);
    }
    return [$p, $pipes];
}
function stop(array $s): void
{
    proc_terminate($s[0], 9);
    proc_close($s[0]);
}

$results = []; // bench => runtime => ['rate'=>, 'mb'=>, 'ok'=>] ; churn => runtime => array
$port = 41000;
foreach ($benches as $bench => $kind) {
    if ($filter !== null && !str_contains($bench, $filter)) {
        continue;
    }
    foreach ($runtimes as $rt) {
        $script = "$dir/cases/$bench/$rt.php";
        if ($kind === 'churn') {
            if ($rt === 'react-fiber') {
                continue; // churn has one react variant (timer callbacks)
            }
            $script = "$dir/cases/churn.php";
        } elseif (!is_file($script)) {
            continue;
        }
        $samples = [];
        $n = $kind === 'churn' ? 1 : $runs;
        for ($i = 0; $i < $n; $i++) {
            fprintf(STDERR, "%s %s (%d/%d)\n", $bench, $rt, $i + 1, $n);
            $port++;
            if ($kind === 'run') {
                $r = lastJson(run([...$php, $script]));
            } elseif ($kind === 'churn') {
                $r = lastJson(run([...$php, $script, $rt === 'react-callback' ? 'react' : $rt, '90']));
            } elseif ($kind === 'tcp') {
                $srv = serve([...$php, $script, (string) $port], 'ready');
                $r = lastJson(run([PHP_BINARY, "$dir/cases/tcp-echo/client.php", (string) $port]));
                stop($srv);
                $r['mb'] = null;
            } else {
                $srv = serve([PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/cases/http-fanout/router.php"], null);
                $r = lastJson(run([...$php, $script, "http://127.0.0.1:$port/"]));
                stop($srv);
            }
            if ($r === null) {
                fprintf(STDERR, "  no result\n");
                continue;
            }
            $samples[] = $r;
        }
        if (!$samples) {
            continue;
        }
        if ($kind === 'churn') {
            $results[$bench][$rt] = $samples[0];
            continue;
        }
        $s = median(array_column($samples, 's'));
        $results[$bench][$rt] = [
            'us' => $s / $samples[0]['n'] * 1e6, 's' => $s, 'rate' => $samples[0]['n'] / $s,
            'mb' => $samples[0]['mb'] === null ? null : median(array_column($samples, 'mb')),
            'ok' => !in_array(false, array_column($samples, 'ok'), true),
        ];
    }
}

// Format
$rows = [];
$fmt = function (string $bench, array $r): string {
    return match ($bench) {
        'spawn-trivial', 'spawn-suspend-once' => sprintf('%.1f us/op', $r['us']),
        'sleepers-10k' => sprintf('%.3f s', $r['s']),
        'tcp-echo' => sprintf('%s msg/s', number_format($r['rate'])),
        'http-fanout' => sprintf('%s req/s', number_format($r['rate'])),
    };
};
$table = "| benchmark | " . implode(' | ', $runtimes) . " |\n|---|" . str_repeat('---|', count($runtimes)) . "\n";
foreach ($results as $bench => $byRt) {
    if ($bench === 'churn') {
        continue;
    }
    $cells = [];
    foreach ($runtimes as $rt) {
        $r = $byRt[$rt] ?? null;
        $cells[] = $r === null ? '-' : $fmt($bench, $r) . ($r['mb'] !== null ? sprintf(', %.0f MB', $r['mb']) : '') . ($r['ok'] ?? true ? '' : ' (WRONG RESULT)');
    }
    $table .= "| $bench | " . implode(' | ', $cells) . " |\n";
}
if (isset($results['churn'])) {
    $table .= "\n90 s churn, 1,000 workers sleeping 1 ms, one run each (last sample):\n\n| runtime | RSS MB | gc runs | gc collected | gc roots | ops | p50 ms | p99 ms | max ms |\n|---|---|---|---|---|---|---|---|---|\n";
    foreach ($results['churn'] as $rt => $c) {
        $table .= sprintf("| %s | %s | %d | %d | %d | %d | %s | %s | %s |\n", $rt === 'react-callback' ? 'react (callback)' : $rt, $c['rss_mb'], $c['gc_runs'], $c['gc_collected'], $c['gc_roots'], $c['ops'], $c['p50_ms'], $c['p99_ms'], $c['max_ms']);
    }
}
echo $table;

// Header
$cpu = 'unknown';
foreach (@file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    if (str_starts_with($l, 'model name')) {
        $cpu = trim(explode(':', $l, 2)[1]);
        break;
    }
}
$pk = [];
foreach (explode("\n", run(['composer', 'show', '-d', $dir, '--no-ansi'])) as $l) {
    if (preg_match('~^(phasync/phasync|amphp/\S+|revolt/\S+|react/\S+)\s+(\S+(?: \S+)?)~', $l, $m) && preg_match('~^(\S+)\s+(\S+)~', $l, $mm)) {
        $pk[] = "- {$mm[1]} {$mm[2]}";
    }
}
$host = explode('.', gethostname())[0];
$md = "# Benchmark results " . date('Y-m-d') . " ($host)\n\n"
    . "- Date: " . date('Y-m-d H:i') . "\n- PHP: " . PHP_VERSION . "\n"
    . "- JIT: " . ($jit ? 'on (-d opcache.enable_cli=1 -d opcache.jit=1255 -d opcache.jit_buffer_size=64M)' : 'off') . "\n"
    . "- CPU: $cpu\n- phasync commit: " . run(['git', '-C', $dir, 'rev-parse', '--short', 'HEAD']) . "\n- Runs per cell: median of $runs (churn: 1)\n- Packages:\n" . implode("\n", $pk) . "\n\n" . $table;
$file = "$dir/results/" . date('Y-m-d') . "-$host" . ($jit ? '-jit' : '') . ($filter ? "-$filter" : '') . ".md";
file_put_contents($file, $md);
fprintf(STDERR, "written %s\n", $file);
