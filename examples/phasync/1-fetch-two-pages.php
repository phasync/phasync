<?php

// Two coroutines wait at the same time: the run takes as long as the slower one, not the sum.
$fetch = function (string $url, float $seconds): string {
    phasync::sleep($seconds);   // stands in for the network
    echo "fetched $url\n";

    return strtoupper(basename($url));
};

$pages = phasync::run(function () use ($fetch) {
    $about = phasync::go($fetch, 'https://example.com/about', 0.06);
    $blog  = phasync::go($fetch, 'https://example.com/blog', 0.03);

    return [phasync::await($about), phasync::await($blog)];
});

echo implode(', ', $pages), "\n";
