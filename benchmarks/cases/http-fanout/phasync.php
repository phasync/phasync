<?php require __DIR__ . '/../../vendor/autoload.php'; use phasync\Services\CurlMulti; $t = hrtime(true); $u = $argv[1]; $n = 0;
phasync::run(function () use ($u, &$n) { $fs = []; for ($i = 0; $i < 1000; $i++) $fs[] = phasync::go(function () use ($u) { $ch = curl_init($u); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); $r = CurlMulti::await($ch); return curl_multi_getcontent($ch) ?? $r; });
 foreach ($fs as $f) { if (phasync::await($f) === 'hello world') $n++; } });
echo json_encode(['s' => (hrtime(true) - $t) / 1e9, 'mb' => memory_get_peak_usage(true) / 1048576, 'n' => 1000, 'ok' => $n === 1000]), "\n";
