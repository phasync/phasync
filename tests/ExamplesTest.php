<?php

/*
 * The @example files of the docblocks: the reference runs each one and shows its output, so each
 * must run on its own, the way the docs build runs it (Composer's autoloader prepended).
 */
$files = \glob(\dirname(__DIR__) . '/examples/*/*.php');

test('example runs', function (string $file) {
    $p = \proc_open(
        [\PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'auto_prepend_file=' . \dirname(__DIR__) . '/vendor/autoload.php', $file],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        \dirname(__DIR__),
    );
    $output = \stream_get_contents($pipes[1]);
    $exit   = \proc_close($p);

    expect($exit)->toBe(0, $output);
    expect($output)->not->toMatch('/(Warning|Notice|Deprecated|Fatal error|Uncaught):/');
})->with(\array_combine(\array_map(static fn ($f) => \basename(\dirname($f)) . '/' . \basename($f), $files), \array_map(static fn ($f) => [$f], $files)));
