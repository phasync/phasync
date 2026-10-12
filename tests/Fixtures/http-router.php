<?php

// Router for `php -S` in tests that make HTTP requests: /get answers at once, /delay/<seconds> after a sleep.
if (\preg_match('#^/delay/(\d+(?:\.\d+)?)$#', \parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH), $m)) {
    \usleep((int) ((float) $m[1] * 1e6));
}
\header('Content-Type: application/json');
echo \json_encode(['url' => $_SERVER['REQUEST_URI']]);
