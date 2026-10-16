<?php

use phasync\Internal\Debug;

final class DebugCovLeakA
{
    public static array $bucket   = [1, 2, 3];
    public static ?object $holder = null;
}

final class DebugCovLeakHolder
{
    public int $visible    = 7;
    private string $hidden = 'secret';

    public function hidden(): string
    {
        return $this->hidden;
    }
}

final class DebugCovLeakB
{
    public static string $name = 'b';
}

final class DebugCovCountable implements Countable
{
    public function count(): int
    {
        return 4;
    }
}

test('binaryString leaves printable text untouched', function () {
    expect(Debug::binaryString('hello world'))->toBe('hello world');
});

test('binaryString wraps binary runs in hex brackets', function () {
    expect(Debug::binaryString("ab\x00\x01cd"))->toBe('ab⟪0001⟫cd');
});

test('binaryString chunks hex in groups of four bytes', function () {
    expect(Debug::binaryString("\x00\x01\x02\x03\x04\x05"))->toBe('⟪00010203∙0405⟫');
});

test('binaryString keeps short printable gaps inside one binary run', function () {
    expect(Debug::binaryString("\x00a\x01"))->toBe('⟪006101⟫');
});

test('binaryString prints the length only for runs longer than 8 bytes', function () {
    $nine  = \str_repeat("\x01", 9);
    $eight = \str_repeat("\x01", 8);
    expect(Debug::binaryString($nine, true))->toBe('⟪9|01010101∙01010101∙01⟫');
    expect(Debug::binaryString($eight, true))->toBe('⟪01010101∙01010101⟫');
    expect(Debug::binaryString($nine))->toBe('⟪01010101∙01010101∙01⟫');
});

test('getDebugInfo describes a suspended fiber with its executing line and callable', function () {
    $fiber = new Fiber(function () {
        Fiber::suspend();
    });
    $fiber->start();
    $info = Debug::getDebugInfo($fiber);
    expect($info)->toMatch('/^Fiber' . \spl_object_id($fiber) . '\(suspended at .*DebugCoverageTest\.php\(\d+\), Closure\d+\(.*DebugCoverageTest\.php\(\d+\)\)\)$/');
    $fiber->resume();
});

test('getDebugInfo describes a terminated fiber', function () {
    $fiber = new Fiber(function () {});
    $fiber->start();
    expect(Debug::getDebugInfo($fiber))->toBe('Fiber' . \spl_object_id($fiber) . '(terminated, NULL)');
});

test('getDebugInfo describes a running fiber', function () {
    $info  = null;
    $fiber = new Fiber(function () use (&$info, &$fiber) {
        $info = Debug::getDebugInfo($fiber);
    });
    $fiber->start();
    expect($info)->toStartWith('Fiber' . \spl_object_id($fiber) . '(running, Closure');
});

test('getDebugInfo describes a fiber that has not started as unknown', function () {
    $fiber = new Fiber(function () {});
    expect(Debug::getDebugInfo($fiber))->toStartWith('Fiber' . \spl_object_id($fiber) . '(unknown, Closure');
});

test('getDebugInfo describes a closure by id, file and start line', function () {
    $closure = function () {};
    $line    = (new ReflectionFunction($closure))->getStartLine();
    expect(Debug::getDebugInfo($closure))->toMatch('/^Closure' . \spl_object_id($closure) . '\(.*DebugCoverageTest\.php\(' . $line . '\)\)$/');
});

test('getDebugInfo falls back to unknown file for internal closures', function () {
    $closure = \strlen(...);
    expect(Debug::getDebugInfo($closure))->toBe('Closure' . \spl_object_id($closure) . '(unknown file(0))');
});

test('getDebugInfo summarises a WeakMap with at most two entries', function () {
    $map  = new WeakMap();
    $keys = [new stdClass(), new stdClass(), new stdClass(), new stdClass()];
    foreach ($keys as $i => $key) {
        $map[$key] = $i;
    }
    $info = Debug::getDebugInfo($map);
    expect($info)->toStartWith('WeakMap' . \spl_object_id($map) . '(count=4 ');
    expect($info)->toContain('stdClass' . \spl_object_id($keys[0]) . '=>int(0)');
    expect($info)->toContain('stdClass' . \spl_object_id($keys[1]) . '=>int(1)');
    expect($info)->not->toContain('int(2)');
});

test('getDebugInfo describes an empty WeakMap', function () {
    $map = new WeakMap();
    expect(Debug::getDebugInfo($map))->toBe('WeakMap' . \spl_object_id($map) . '(count=0)');
});

test('getDebugInfo describes plain and countable objects', function () {
    $plain     = new stdClass();
    $countable = new DebugCovCountable();
    expect(Debug::getDebugInfo($plain))->toBe('stdClass' . \spl_object_id($plain));
    expect(Debug::getDebugInfo($countable))->toBe('DebugCovCountable' . \spl_object_id($countable) . '(count=4)');
});

test('getDebugInfo describes arrays, scalars, null and unsupported types', function () {
    expect(Debug::getDebugInfo([1, 2, 3]))->toBe('array(length=3)');
    expect(Debug::getDebugInfo(42))->toBe('int(42)');
    expect(Debug::getDebugInfo('x'))->toBe('string(x)');
    expect(Debug::getDebugInfo(null))->toBe('NULL');
    $resource = \fopen('php://memory', 'r');
    expect(Debug::getDebugInfo($resource))->toBe('Unsupported subject type (resource (stream)).');
    \fclose($resource);
});

test('findLeaks collects static properties of classes matching a namespace prefix', function () {
    DebugCovLeakA::$holder = new DebugCovLeakHolder();
    try {
        $leaks = Debug::findLeaks('DebugCovLeakA');
        expect($leaks)->toHaveKey('DebugCovLeakA::$bucket');
        expect($leaks['DebugCovLeakA::$bucket'])->toBe([1, 2, 3]);
        expect($leaks['DebugCovLeakA::$holder'])->toBe(DebugCovLeakA::$holder);
        // Instance properties of objects held in statics are included, other classes are not
        expect($leaks['DebugCovLeakA::$holder->visible'])->toBe(7);
        expect($leaks['DebugCovLeakA::$holder->hidden'])->toBe('secret');
        expect($leaks)->not->toHaveKey('DebugCovLeakB::$name');
    } finally {
        DebugCovLeakA::$holder = null;
    }
});

test('findLeaks accepts an array of namespace prefixes', function () {
    $leaks = Debug::findLeaks(['DebugCovLeakA', 'DebugCovLeakB', 'No\\Such\\Prefix']);
    expect($leaks)->toHaveKeys(['DebugCovLeakA::$bucket', 'DebugCovLeakB::$name']);
    expect($leaks['DebugCovLeakB::$name'])->toBe('b');
    expect($leaks)->not->toHaveKey('phasync\\EventLoop::$instance');
});

test('findLeaks with an array matching nothing returns an empty array', function () {
    expect(Debug::findLeaks(['No\\Such\\Prefix']))->toBe([]);
});
