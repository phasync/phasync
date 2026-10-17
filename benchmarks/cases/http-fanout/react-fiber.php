<?php require __DIR__ . '/../../vendor/autoload.php'; use function React\Async\async; use function React\Async\await; $t = hrtime(true); $b = new React\Http\Browser(); $u = $argv[1];
$ps = []; for ($i = 0; $i < 1000; $i++) $ps[] = async(fn() => (string) await($b->get($u))->getBody())();
$r = await(React\Promise\all($ps));
echo json_encode(['s' => (hrtime(true) - $t) / 1e9, 'mb' => memory_get_peak_usage(true) / 1048576, 'n' => 1000, 'ok' => count(array_filter($r, fn($x) => $x === 'hello world')) === 1000]), "\n";
