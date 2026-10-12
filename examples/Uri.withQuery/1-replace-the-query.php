<?php

use phasync\Psr\Uri;

$uri = new Uri('https://example.com/search?q=old#results');

echo $uri->withQuery('q=new&page=2'), "\n";
echo $uri->withQuery(http_build_query(['q' => 'two words', 'page' => 1])), "\n";
echo $uri->withQuery(''), "\n";
