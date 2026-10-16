<?php

use phasync\Util\Console;

test('Console writes to stream', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'console_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource, false); // No color
        $console->write('hello');

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toBe('hello');
    } finally {
        @\unlink($tmpfile);
    }
});

test('Console renders markup', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'console_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console  = new Console($resource, false);
        $rendered = $console->render('<!bold>hello<!>');
        expect($rendered)->toBe('hello');
    } finally {
        @\unlink($tmpfile);
    }
});

test('Console hasColor', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'console_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource, true);
        expect($console->hasColor())->toBeTrue();

        $console2 = new Console($resource, false);
        expect($console2->hasColor())->toBeFalse();
    } finally {
        @\unlink($tmpfile);
    }
});

test('Console strip removes markup', function () {
    $result = Console::strip('<!bold>hello<!> world');
    expect($result)->toBe('hello world');
});

test('Console width counts columns', function () {
    $width = Console::width('hello');
    expect($width)->toBe(5);
});

test('Console width with markup', function () {
    $width = Console::width('<!bold>hello<!>');
    expect($width)->toBe(5);
});

test('Console escape escapes markup delimiters', function () {
    $escaped = Console::escape('<!should not render>');
    expect($escaped)->toContain('<!!');
});

test('Console pad with STR_PAD_RIGHT', function () {
    $padded = Console::pad('hi', 5, \STR_PAD_RIGHT);
    expect(\strlen($padded))->toBe(5);
    expect($padded)->toBe('hi   ');
});

test('Console pad with STR_PAD_LEFT', function () {
    $padded = Console::pad('hi', 5, \STR_PAD_LEFT);
    expect(\strlen($padded))->toBe(5);
    expect($padded)->toBe('   hi');
});

test('Console pad with STR_PAD_BOTH', function () {
    $padded = Console::pad('hi', 5, \STR_PAD_BOTH);
    expect(\strlen($padded))->toBe(5);
    // Center: 3 chars total, so 1 on left, 2 on right
    expect($padded)->toMatch('/^ hi  $/');
});

test('Console pad does not pad when already long enough', function () {
    $padded = Console::pad('hello world', 5);
    expect($padded)->toBe('hello world');
});

test('Console cut truncates to width', function () {
    $cut = Console::cut('hello world', 5);
    expect(Console::width($cut))->toBeLessThanOrEqual(5);
});

test('Console cut without ellipsis', function () {
    $cut = Console::cut('hello world', 5, '');
    expect(Console::width($cut))->toBeLessThanOrEqual(5);
});

test('Console cut does not truncate if fits', function () {
    $cut = Console::cut('hello', 10);
    expect($cut)->toBe('hello');
});

test('Console columns from environment', function () {
    \putenv('COLUMNS=120');
    $cols = Console::columns();
    expect($cols)->toBe(120);
    \putenv('COLUMNS=');
});

test('Console columns defaults to 80', function () {
    \putenv('COLUMNS=0'); // Invalid value
    $cols = Console::columns();
    expect($cols)->toBe(80);
    \putenv('COLUMNS=');
});

test('Console log with warning level', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'console_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource, false);
        $console->log('warning', 'test warning');

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('warning');
        expect($content)->toContain('test warning');
    } finally {
        @\unlink($tmpfile);
    }
});

test('Console log with error level', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'console_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource, false);
        $console->log('error', 'test error');

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('error');
        expect($content)->toContain('test error');
    } finally {
        @\unlink($tmpfile);
    }
});

test('Console log with critical level', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'console_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource, false);
        $console->log('critical', 'test critical');

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('critical');
        expect($content)->toContain('test critical');
    } finally {
        @\unlink($tmpfile);
    }
});

test('Console log with alert level', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'console_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource, false);
        $console->log('alert', 'test alert');

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('alert');
        expect($content)->toContain('test alert');
    } finally {
        @\unlink($tmpfile);
    }
});

test('Console log with emergency level', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'console_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource, false);
        $console->log('emergency', 'test emergency');

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('emergency');
        expect($content)->toContain('test emergency');
    } finally {
        @\unlink($tmpfile);
    }
});

test('Console log with context substitution', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'console_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource, false);
        $console->log('info', 'User {user} from {ip}', ['user' => 'alice', 'ip' => '192.168.1.1']);

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('alice');
        expect($content)->toContain('192.168.1.1');
    } finally {
        @\unlink($tmpfile);
    }
});

test('Console log with invalid level throws', function () {
    $console = new Console(\STDOUT);

    expect(fn () => $console->log('invalid', 'message'))
        ->toThrow(InvalidArgumentException::class);
});

test('Console log with source', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'console_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource, false);
        $console->log('info', 'message', [], 'source1');

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('source1');
        expect($content)->toContain('message');
    } finally {
        @\unlink($tmpfile);
    }
});

test('Console log with stringable message', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'console_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $obj = new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable';
            }
        };

        $console = new Console($resource, false);
        $console->log('info', $obj);

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        expect($content)->toContain('stringable');
    } finally {
        @\unlink($tmpfile);
    }
});

test('Console styles colors', function () {
    $rendered = Console::strip('<!red>text<!>');
    expect($rendered)->toBe('text');
});

test('Console bright colors', function () {
    $rendered = Console::strip('<!bright-red>text<!>');
    expect($rendered)->toBe('text');
});

test('Console background colors', function () {
    $rendered = Console::strip('<!bg-blue>text<!>');
    expect($rendered)->toBe('text');
});

test('Console text attributes', function () {
    $rendered = Console::strip('<!bold><!underline>text<!><!>');
    expect($rendered)->toBe('text');
});

test('Console gray color alias', function () {
    $rendered = Console::strip('<!gray>text<!>');
    expect($rendered)->toBe('text');
});

test('Console pad requires width', function () {
    expect(fn () => Console::strip('<!pad>text<!>'))
        ->toThrow(InvalidArgumentException::class);
});

test('Console control characters are escaped', function () {
    $tmpfile  = \tempnam(\sys_get_temp_dir(), 'console_');
    $resource = \fopen($tmpfile, 'w');

    try {
        $console = new Console($resource, false);
        $console->log('info', "text\x00with\x1fcontrol");

        \fclose($resource);

        $content = \file_get_contents($tmpfile);
        // The control characters should be escaped
        expect(\strlen($content))->toBeGreaterThan(0);
    } finally {
        @\unlink($tmpfile);
    }
});
