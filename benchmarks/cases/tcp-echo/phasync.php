<?php // server; usage: phasync.php PORT
require __DIR__ . '/../../vendor/autoload.php';
phasync::run(function () use ($argv) { $s = stream_socket_server('tcp://127.0.0.1:' . $argv[1]); stream_set_blocking($s, false); echo "ready\n";
 while (true) { phasync::readable($s); $c = @stream_socket_accept($s, 0); if (!$c) continue; stream_set_blocking($c, false);
  phasync::go(function () use ($c) { while (true) { phasync::readable($c); $d = fread($c, 65536); if ($d === '' || $d === false) { if (feof($c)) break; continue; } fwrite($c, $d); } fclose($c); }); } });
