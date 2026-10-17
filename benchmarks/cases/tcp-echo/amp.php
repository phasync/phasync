<?php require __DIR__ . '/../../vendor/autoload.php'; use function Amp\async;
$s = Amp\Socket\listen('127.0.0.1:' . $argv[1]); echo "ready\n";
while ($c = $s->accept()) async(function () use ($c) { while (null !== ($d = $c->read())) $c->write($d); });
