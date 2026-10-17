<?php require __DIR__ . '/../../vendor/autoload.php'; use function React\Async\async; use function React\Async\await; use function React\Async\delay; $t = hrtime(true);
$ps = []; for ($i = 0; $i < 10000; $i++) $ps[] = async(fn() => delay(0.001))(); await(React\Promise\all($ps));
echo json_encode(['s' => (hrtime(true) - $t) / 1e9, 'mb' => memory_get_peak_usage(true) / 1048576, 'n' => 10000]), "\n";
