<?php

use phasync\Psr\PsrFactory;

test('a response from PsrFactory has an empty body, and every with*() works on it', function () {
    $f        = new PsrFactory();
    $response = $f->createResponse(404);
    expect((string) $response->getBody())->toBe('');

    $response = $response->withBody($f->createStream('x'))->withStatus(200)->withHeader('A', 'b');
    expect([(string) $response->getBody(), $response->getStatusCode(), $response->getHeaderLine('A')])->toBe(['x', 200, 'b']);
});
