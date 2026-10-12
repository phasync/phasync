<?php

use phasync\Psr\StreamFactory;

phasync::run(function () {
    $file = tmpfile();
    fwrite($file, 'from a stream resource');
    rewind($file);

    $sources = [
        'string'           => 'from a string',
        'null'             => null,
        'resource'         => $file,
        'JsonSerializable' => new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['from' => 'JsonSerializable'];
            }
        },
    ];
    foreach ($sources as $kind => $source) {
        $stream = StreamFactory::create($source);   // a Psr\Http\Message\StreamInterface
        printf("%-17s %s\n", $kind, json_encode($stream->getContents()));
    }

    try {
        StreamFactory::create(42);
    } catch (InvalidArgumentException $e) {
        echo 'an int: ', get_class($e), "\n";
    }
});
