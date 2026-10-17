<?php // Loop floor: a resolved promise plus a then-callback. No coroutine, no fiber.
require __DIR__ . '/../../vendor/autoload.php'; use React\Promise\Promise; $t = hrtime(true); $s = 0;
for ($i = 0; $i < 1000000; $i++) { (new Promise(fn($r) => $r(1)))->then(function ($v) use (&$s) { $s += $v; }); }
echo json_encode(['s' => (hrtime(true) - $t) / 1e9, 'mb' => memory_get_peak_usage(true) / 1048576, 'n' => 1000000, 'ok' => $s === 1000000]), "\n";
