<?php

use phasync\Util\LruCache;

test('stores, reads, deletes and clears', function () {
    $cache = new LruCache();
    expect($cache->set('a', 1))->toBeTrue();
    expect([$cache->get('a'), $cache->get('missing', 'default'), $cache->has('a'), $cache->has('missing')])->toBe([1, 'default', true, false]);
    expect([$cache->delete('a'), $cache->delete('a'), $cache->get('a')])->toBe([true, false, null]);
    $cache->set('b', null); // a stored null is an entry
    expect([$cache->has('b'), $cache->get('b', 'default')])->toBe([true, null]);
    $cache->clear();
    expect(\count($cache))->toBe(0);
});

test('past maxEntries, the least recently used entry goes first; reading one makes it recent', function () {
    $cache = new LruCache(maxEntries: 3);
    $cache->set('a', 1);
    $cache->set('b', 2);
    $cache->set('c', 3);
    $cache->get('a');      // b is now the least recently used
    $cache->has('c');
    $cache->set('d', 4);
    expect([$cache->has('a'), $cache->has('b'), $cache->has('c'), $cache->has('d'), \count($cache)])->toBe([true, false, true, true, 3]);
    // The has() calls above made the order a, c, d. Setting makes an entry recent too: c, d, a
    $cache->set('a', 5);
    $cache->set('e', 6);
    expect([$cache->get('a'), $cache->has('c'), $cache->has('d')])->toBe([5, false, true]);
});

test('past maxBytes, entries are evicted by the bytes of their key and string value', function () {
    $cache = new LruCache(maxBytes: 30);
    $cache->set('k1', \str_repeat('x', 10)); // 12 bytes
    $cache->set('k2', \str_repeat('y', 10)); // 24
    expect($cache->bytes())->toBe(24);
    $cache->set('k3', \str_repeat('z', 10)); // 36: k1 goes
    expect([$cache->has('k1'), $cache->has('k2'), $cache->has('k3'), $cache->bytes()])->toBe([false, true, true, 24]);
    $cache->set('k2', 'short');             // replacing counts the new size
    expect($cache->bytes())->toBe(19);
    $cache->delete('k3');
    expect($cache->bytes())->toBe(7);
});

test('a value larger than maxBytes is refused, and the old value under its key removed', function () {
    $cache = new LruCache(maxBytes: 20);
    $cache->set('a', 'small');
    $cache->set('b', 'kept');
    expect($cache->set('a', \str_repeat('x', 100)))->toBeFalse();
    expect([$cache->has('a'), $cache->get('b'), $cache->bytes()])->toBe([false, 'kept', 5]);
});

test('an entry with a TTL expires; others stay', function () {
    $cache = new LruCache();
    $cache->set('short', 1, ttl: 0.02);
    $cache->set('long', 2, ttl: 60);
    $cache->set('forever', 3);
    expect($cache->get('short'))->toBe(1);
    \usleep(30_000);
    expect([$cache->get('short', 'gone'), $cache->has('short'), $cache->get('long'), $cache->get('forever'), \count($cache)])->toBe(['gone', false, 2, 3, 2]);
    $cache->set('long', 4);                 // set again without a TTL: no longer expires
    expect($cache->get('long'))->toBe(4);
});

test('numeric string keys work like any other', function () {
    $cache = new LruCache(maxEntries: 2);
    $cache->set('1', 'one');
    $cache->set('02', 'two');
    $cache->set('3', 'three');
    expect([$cache->has('1'), $cache->get('02'), $cache->get('3')])->toBe([false, 'two', 'three']);
});

test('limits below one are refused', function () {
    expect(fn () => new LruCache(maxEntries: 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => new LruCache(maxBytes: 0))->toThrow(InvalidArgumentException::class);
});
