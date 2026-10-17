<?php // Blocking client, identical for every runtime. usage: client.php PORT
$c = stream_socket_client('tcp://127.0.0.1:' . $argv[1]); $m = str_repeat('x', 64); $N = 100000; $t = hrtime(true);
for ($i = 0; $i < $N; $i++) { fwrite($c, $m); $r = ''; while (strlen($r) < 64) $r .= fread($c, 64 - strlen($r)); }
echo json_encode(['s' => (hrtime(true) - $t) / 1e9, 'n' => $N]), "\n";
