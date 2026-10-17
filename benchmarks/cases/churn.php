<?php // usage: churn.php phasync|amp|react [seconds]. 1,000 workers loop on a 1 ms sleep; samples go to stderr, last sample to stdout.
require __DIR__ . '/../vendor/autoload.php'; $rt = $argv[1]; $T = (int) ($argv[2] ?? 90);
$lat = []; $t0 = hrtime(true); $stop = false; $ops = 0; $last = [];
function rec($s) { global $lat, $ops; $lat[] = hrtime(true) - $s; $ops++; }
function sample() { global $lat, $ops, $last, $rt, $t0; sort($lat); $n = count($lat); $g = gc_status();
 $rss = (int) preg_replace('/\D/', '', shell_exec('grep VmRSS /proc/' . getmypid() . '/status'));
 $last = ['rss_mb' => round($rss / 1024, 1), 'gc_runs' => $g['runs'], 'gc_collected' => $g['collected'], 'gc_roots' => $g['roots'], 'ops' => $ops, 'p50_ms' => round($n ? $lat[intdiv($n, 2)] / 1e6 : 0, 2), 'p99_ms' => round($n ? $lat[(int) ($n * .99)] / 1e6 : 0, 2), 'max_ms' => round($n ? $lat[$n - 1] / 1e6 : 0, 2)];
 fprintf(STDERR, "%s t=%d %s\n", $rt, (hrtime(true) - $t0) / 1e9, json_encode($last)); $lat = []; }
$over = fn() => (hrtime(true) - $t0) / 1e9 >= $T;
if ($rt == 'react') { $L = React\EventLoop\Loop::get(); $sp = function () use (&$sp, $L) { $s = hrtime(true); $L->addTimer(0.001, function () use ($s, &$sp) { rec($s); if (!$GLOBALS['stop']) $sp(); }); };
 for ($i = 0; $i < 1000; $i++) $sp(); $L->addPeriodicTimer(5, function () use ($over, $L) { sample(); if ($over()) { $GLOBALS['stop'] = true; $L->stop(); } }); $L->run(); }
elseif ($rt == 'amp') { for ($i = 0; $i < 1000; $i++) Amp\async(function () { while (!$GLOBALS['stop']) { $s = hrtime(true); Amp\delay(0.001); rec($s); } });
 while (!$over()) { Amp\delay(5); sample(); } $stop = true; }
else { phasync::run(function () use ($over) { for ($i = 0; $i < 1000; $i++) phasync::go(function () { while (!$GLOBALS['stop']) { $s = hrtime(true); phasync::sleep(0.001); rec($s); } });
 while (!$over()) { phasync::sleep(5); sample(); } $GLOBALS['stop'] = true; }); }
echo json_encode($last), "\n";
