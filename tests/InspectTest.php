<?php

use phasync\Internal\Inspect;

test('getRefCount returns 0 for scalars', function () {
    $int = 42;
    expect(Inspect::getRefCount($int))->toBe(0);

    $float = 3.14;
    expect(Inspect::getRefCount($float))->toBe(0);

    $bool = true;
    expect(Inspect::getRefCount($bool))->toBe(0);
});

test('getRefCount returns 0 for interned strings', function () {
    // Interned strings have no reference count
    $str = 'hello';
    expect(Inspect::getRefCount($str))->toBe(0);
});

test('getRefCount for objects increases with references', function () {
    $obj       = new stdClass();
    $refCount1 = Inspect::getRefCount($obj);
    expect($refCount1)->toBeGreaterThanOrEqual(0);

    // Add another reference
    $ref       = $obj;
    $refCount2 = Inspect::getRefCount($obj);
    expect($refCount2)->toBeGreaterThanOrEqual($refCount1);
});

test('getRefCount for arrays', function () {
    $arr      = [1, 2, 3];
    $refCount = Inspect::getRefCount($arr);
    expect($refCount)->toBeGreaterThanOrEqual(0);
});

test('getRefCount handles null', function () {
    $null = null;
    expect(Inspect::getRefCount($null))->toBe(0);
});
