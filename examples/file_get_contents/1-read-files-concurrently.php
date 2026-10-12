<?php

use function phasync\file_get_contents;

$dir = sys_get_temp_dir() . '/phasync-example-' . getmypid();
mkdir($dir);
file_put_contents("$dir/a.json", json_encode(['name' => 'a']));
file_put_contents("$dir/b.json", json_encode(['name' => 'b']));

phasync::run(function () use ($dir) {
    foreach (['a', 'b'] as $name) {
        phasync::go(function () use ($dir, $name) {
            $config = json_decode(file_get_contents("$dir/$name.json"), true);
            echo "loaded {$config['name']}.json\n";
        });
    }
});

array_map('unlink', glob("$dir/*"));
rmdir($dir);
