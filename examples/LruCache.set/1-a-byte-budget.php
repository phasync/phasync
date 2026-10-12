<?php

use phasync\Util\LruCache;

$cache = new LruCache(maxBytes: 20);              // keys and string values count

var_dump($cache->set('k1', str_repeat('x', 8)));  // 10 bytes
var_dump($cache->set('k2', str_repeat('y', 8)));  // 10 bytes: 20 in all
echo $cache->count(), ' entries, ', $cache->bytes(), " bytes\n";

$cache->set('k3', 'zz');                          // 4 more bytes: 'k1' is evicted
echo $cache->count(), ' entries, ', $cache->bytes(), " bytes, k1 ", $cache->has('k1') ? 'kept' : 'evicted', "\n";

var_dump($cache->set('k2', str_repeat('!', 30))); // larger than the cache: not stored,
var_dump($cache->has('k2'));                      // and the old value is gone too
