<?php

use phasync\Util\LruCache;

$cache = new LruCache();
$cache->set('session:1', ['user' => 'ann'], ttl: 0.05);

var_dump($cache->get('session:1'));
usleep(60_000);
var_dump($cache->get('session:1', 'expired'));   // gone after 0.05 s
var_dump($cache->get('session:2'));              // never set: null
