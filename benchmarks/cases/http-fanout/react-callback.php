<?php // Promise style; react/http Browser has no blocking API outside react/async.
require __DIR__ . '/../../vendor/autoload.php'; $t = hrtime(true); $b = new React\Http\Browser(); $ps = [];
for ($i = 0; $i < 1000; $i++) $ps[] = $b->get($argv[1])->then(fn($r) => (string) $r->getBody());
React\Promise\all($ps)->then(function ($r) use ($t) { echo json_encode(['s' => (hrtime(true) - $t) / 1e9, 'mb' => memory_get_peak_usage(true) / 1048576, 'n' => 1000, 'ok' => count(array_filter($r, fn($b) => $b === 'hello world')) === 1000]), "\n"; });
