<?php

test('async TimeoutException interface exists', function () {
    expect(\interface_exists(async\TimeoutException::class))->toBeTrue();
});

test('async CancelledException interface exists', function () {
    expect(\interface_exists(async\CancelledException::class))->toBeTrue();
});

test('phasync TimeoutException implements async TimeoutException', function () {
    $e = new phasync\TimeoutException('test');
    expect($e)->toBeInstanceOf(async\TimeoutException::class);
});

test('phasync CancelledException implements async CancelledException', function () {
    $e = new phasync\CancelledException('test');
    expect($e)->toBeInstanceOf(async\CancelledException::class);
});

test('async_interfaces.php declares the interfaces when the api is not installed', function () {
    // The autoloader always loads the file, so run it alone in a process that has not
    $code = \escapeshellarg('require ' . \var_export(__DIR__ . '/../src/async_interfaces.php', true) . ';'
        . 'exit(interface_exists("async\\\\TimeoutException", false) && interface_exists("async\\\\CancelledException", false) ? 0 : 1);');
    \exec(\escapeshellarg(\PHP_BINARY) . ' -n -r ' . $code . ' 2>&1', $output, $status);
    expect($status)->toBe(0, \implode("\n", $output));
});

test('async_interfaces.php leaves the interfaces of an installed api alone', function () {
    $code = \escapeshellarg('namespace async { interface TimeoutException extends \Throwable { const API = 1; } interface CancelledException extends \Throwable {} }'
        . ' namespace { require ' . \var_export(__DIR__ . '/../src/async_interfaces.php', true) . ';'
        . ' exit(\async\TimeoutException::API === 1 ? 0 : 1); }');
    \exec(\escapeshellarg(\PHP_BINARY) . ' -n -r ' . $code . ' 2>&1', $output, $status);
    expect($status)->toBe(0, \implode("\n", $output));
});
