<?php require __DIR__ . '/../../vendor/autoload.php'; use function Amp\async; use function Amp\delay; $N = 100000; $t = hrtime(true); $s = 0;
for ($i = 0; $i < $N; $i++) { $s += async(function () { delay(0); return 1; })->await(); }
echo json_encode(['s' => (hrtime(true) - $t) / 1e9, 'mb' => memory_get_peak_usage(true) / 1048576, 'n' => $N, 'ok' => $s === $N]), "\n";
