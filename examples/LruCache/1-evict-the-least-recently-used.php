<?php

use phasync\Util\LruCache;

$cache = new LruCache(maxEntries: 2);
$cache->set('a', 'apple');
$cache->set('b', 'banana');
$cache->get('a');                 // 'a' is now the most recently used
$cache->set('c', 'cherry');       // full: 'b' goes

var_dump($cache->has('a'), $cache->has('b'), $cache->has('c'));
