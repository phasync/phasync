<?php

use phasync\Util\Console;

function consoleOutput(bool $color, string $markup): string
{
    $stream = \fopen('php://memory', 'w+');
    (new Console($stream, $color))->write($markup);
    \rewind($stream);

    return \stream_get_contents($stream);
}

test('markup is styled on a terminal and plain when piped', function () {
    $markup = '<!yellow>Usage:<!> <!bold underline>swerve<!> [options]';
    expect(consoleOutput(true, $markup))->toBe("\e[0;33mUsage:\e[0m \e[0;1;4mswerve\e[0m [options]");
    expect(consoleOutput(false, $markup))->toBe('Usage: swerve [options]');
});

test('styles nest, and closing one restores the outer', function () {
    expect(consoleOutput(true, '<!yellow>a<!bold>b<!>c<!>d'))->toBe("\e[0;33ma\e[0;33;1mb\e[0;33mc\e[0md");
});

test('styles left open are closed at the end of the output', function () {
    expect(consoleOutput(true, '<!red>open'))->toBe("\e[0;31mopen\e[0m");
});

test('backgrounds, bright colors and gray', function () {
    expect(consoleOutput(true, '<!bg-red white>x<!><!bright-green>y<!><!gray>z<!>'))
        ->toBe("\e[0;41;37mx\e[0m\e[0;92my\e[0m\e[0;90mz\e[0m");
});

test('an unknown style is an error', function () {
    expect(fn () => consoleOutput(true, '<!purple>x<!>'))->toThrow(InvalidArgumentException::class, "Unknown console style 'purple'");
});

test('escaped text is shown as it is, styled or not', function () {
    $markup = '<!red>' . Console::escape('a <!bold> b') . '<!>';
    expect(consoleOutput(false, $markup))->toBe('a <!bold> b');
    expect(consoleOutput(true, $markup))->toBe("\e[0;31ma <!bold> b\e[0m");
});

test('width counts display columns: markup 0, wide characters and emoji 2, combining marks 0', function () {
    expect(Console::width('<!red>abc<!>'))->toBe(3);
    expect(Console::width('日本語'))->toBe(6);
    expect(Console::width('🎉!'))->toBe(3);
    expect(Console::width("e\u{0301}"))->toBe(1); // é as e and a combining accent
    expect(Console::width('æøå'))->toBe(3);
});

test('pad aligns columns the same way styled or plain', function () {
    $rows = [Console::pad('<!bold>--http<!>', 12) . '|', Console::pad('--workers', 12) . '|', Console::pad('日本', 12) . '|'];
    foreach ($rows as $row) {
        expect(Console::width($row))->toBe(13);
    }
    expect(Console::pad('7', 4, \STR_PAD_LEFT))->toBe('   7');
    expect(Console::pad('ab', 6, \STR_PAD_BOTH))->toBe('  ab  ');
    expect(Console::pad('too wide', 3))->toBe('too wide');
});

test('cut stops at the width, adds the ellipsis, and closes open styles', function () {
    expect(Console::cut('<!red>hello world<!>', 8, '…'))->toBe('<!red>hello w…<!>');
    expect(Console::width(Console::cut('<!red>hello world<!>', 8, '…')))->toBe(8);
    expect(Console::cut('日本語テキスト', 5))->toBe('日本'); // a wide character never splits a column
    expect(Console::cut('short', 10, '…'))->toBe('short');
});

test('NO_COLOR turns styles off, FORCE_COLOR on, whatever the stream', function () {
    $stream = \fopen('php://memory', 'w+');
    \putenv('NO_COLOR=1');
    expect((new Console($stream))->hasColor())->toBeFalse();
    \putenv('NO_COLOR');
    \putenv('FORCE_COLOR=1');
    expect((new Console($stream))->hasColor())->toBeTrue();
    \putenv('FORCE_COLOR');
    expect((new Console($stream))->hasColor())->toBeFalse(); // not a terminal
});

test('a width in a span pads it, left-aligned by default, the same styled or plain', function () {
    expect(consoleOutput(false, '<!bold pad 8>--http<!>|'))->toBe('--http  |');
    expect(consoleOutput(true, '<!bold pad 8>--http<!>|'))->toBe("\e[0;1m--http  \e[0m|");
    expect(consoleOutput(false, '<!pad 6>ab<!>|'))->toBe('ab    |'); // layout only, no style
});

test('lpad right-aligns, center centers', function () {
    expect(consoleOutput(false, '<!lpad 8>1.2 MB<!>|'))->toBe('  1.2 MB|');
    expect(consoleOutput(false, '<!center 6>ab<!>|'))->toBe('  ab  |');
});

test('longer content is cut with …, or without it using clip', function () {
    expect(consoleOutput(false, '<!pad 8>hello world<!>|'))->toBe('hello w…|');
    expect(consoleOutput(false, '<!pad 8 clip>hello world<!>|'))->toBe('hello wo|');
});

test('widths count what nested styles contain, and wide characters', function () {
    expect(consoleOutput(false, '<!rpad 10>a <!red>日本<!> b<!>|'))->toBe('a 日本 b  |');
    expect(Console::width('<!rpad 10>a <!red>日本<!> b<!>'))->toBe(10);
    expect(consoleOutput(true, '<!pad 6>a<!red>b<!><!>|'))->toBe("a\e[0;31mb\e[0m    |");
});

test('rows of fixed-width spans line up as columns', function () {
    $rows = \array_map(fn ($r) => Console::strip("<!pad 12>{$r[0]}<!><!lpad 6>{$r[1]}<!>"), [['--http', '8080'], ['--workers', 'auto'], ['--public', '-']]);
    expect(\array_map('strlen', $rows))->toBe([18, 18, 18]);
    expect($rows[1])->toBe('--workers     auto');
});

test('a percentage width is of the terminal width', function () {
    \putenv('COLUMNS=40');
    expect(Console::width('<!pad 50%>x<!>'))->toBe(20);
    \putenv('COLUMNS');
});

test('a padding word needs a width', function () {
    expect(fn () => Console::strip('<!pad>x<!>'))->toThrow(InvalidArgumentException::class, "'pad' needs a width");
});
