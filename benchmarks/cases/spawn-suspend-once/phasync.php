<?php require __DIR__ . '/../../vendor/autoload.php'; $N = 100000; $t = hrtime(true); $s = 0;
phasync::run(function () use ($N, &$s) { for ($i = 0; $i < $N; $i++) { $s += phasync::await(phasync::go(function () { phasync::sleep(0); return 1; })); } });
echo json_encode(['s' => (hrtime(true) - $t) / 1e9, 'mb' => memory_get_peak_usage(true) / 1048576, 'n' => $N, 'ok' => $s === $N]), "\n";
