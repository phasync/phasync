<?php require __DIR__ . '/../../vendor/autoload.php'; use function Amp\async; use function Amp\delay; $t = hrtime(true);
$fs = []; for ($i = 0; $i < 10000; $i++) $fs[] = async(fn() => delay(0.001)); Amp\Future\await($fs);
echo json_encode(['s' => (hrtime(true) - $t) / 1e9, 'mb' => memory_get_peak_usage(true) / 1048576, 'n' => 10000]), "\n";
