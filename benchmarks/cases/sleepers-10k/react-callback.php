<?php // Loop floor: 10k timers and promises, no coroutines.
require __DIR__ . '/../../vendor/autoload.php'; use React\EventLoop\Loop; use React\Promise\Deferred; $t = hrtime(true);
$ps = []; for ($i = 0; $i < 10000; $i++) { $d = new Deferred; Loop::addTimer(0.001, fn() => $d->resolve($i)); $ps[] = $d->promise(); }
React\Promise\all($ps)->then(function () use ($t) { echo json_encode(['s' => (hrtime(true) - $t) / 1e9, 'mb' => memory_get_peak_usage(true) / 1048576, 'n' => 10000]), "\n"; });
