<?php

use phasync\Psr\Uri;

echo new Uri('HTTPS://Example.COM:443/a b'), "\n";   // normalised: https://example.com/a%20b

foreach (['http:///path', 'http://example.com:99999/'] as $malformed) {
    try {
        new Uri($malformed);
    } catch (InvalidArgumentException $e) {
        echo $e->getMessage(), "\n";
    }
}
