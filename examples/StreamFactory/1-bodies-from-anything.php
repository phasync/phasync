<?php

use phasync\Psr\Response;

// Response, Request and ServerRequest take their body in any of these forms
$bodies = [
    'a string'           => 'plain text',
    'null'               => null,
    'a JsonSerializable' => new class implements JsonSerializable {
        public function jsonSerialize(): mixed
        {
            return ['ok' => true];
        }
    },
    'a Stringable'       => new class {
        public function __toString(): string
        {
            return 'from __toString()';
        }
    },
];
foreach ($bodies as $kind => $body) {
    $response = new Response(200, [], $body);
    printf("%-20s %s\n", $kind, json_encode((string) $response->getBody()));
}
