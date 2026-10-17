<?php require __DIR__ . '/../../vendor/autoload.php'; use function Amp\async; use Amp\Http\Client\{HttpClientBuilder, Request};
$t = hrtime(true); $c = HttpClientBuilder::buildDefault(); $fs = [];
for ($i = 0; $i < 1000; $i++) $fs[] = async(fn() => $c->request(new Request($argv[1]))->getBody()->buffer());
$r = Amp\Future\await($fs);
echo json_encode(['s' => (hrtime(true) - $t) / 1e9, 'mb' => memory_get_peak_usage(true) / 1048576, 'n' => 1000, 'ok' => count(array_filter($r, fn($b) => $b === 'hello world')) === 1000]), "\n";
