<?php require __DIR__ . '/../../vendor/autoload.php'; $t = hrtime(true);
phasync::run(function () { $fs = []; for ($i = 0; $i < 10000; $i++) $fs[] = phasync::go(fn() => phasync::sleep(0.001)); foreach ($fs as $f) phasync::await($f); });
echo json_encode(['s' => (hrtime(true) - $t) / 1e9, 'mb' => memory_get_peak_usage(true) / 1048576, 'n' => 10000]), "\n";
