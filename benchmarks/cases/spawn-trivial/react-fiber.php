<?php require __DIR__ . '/../../vendor/autoload.php'; use function React\Async\async; use function React\Async\await; $t = hrtime(true); $s = 0;
for ($i = 0; $i < 1000000; $i++) { $s += await(async(fn() => 1)()); }
echo json_encode(['s' => (hrtime(true) - $t) / 1e9, 'mb' => memory_get_peak_usage(true) / 1048576, 'n' => 1000000, 'ok' => $s === 1000000]), "\n";
