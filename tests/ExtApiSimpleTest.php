<?php

/**
 * Additional tests for phasync public ext API in src/ext.php
 * Simplified to avoid environment-specific failures
 */
test('_ext_opt_in returns false for unreadable composer.json', function () {
    $result = \phasync\_ext_opt_in('/nonexistent/file.json');
    expect($result)->toBeFalse();
});

test('_ext_opt_in returns false for malformed JSON', function () {
    $file = \tempnam(\sys_get_temp_dir(), 'phasync-test-');
    try {
        \file_put_contents($file, '{invalid json}');
        expect(\phasync\_ext_opt_in($file))->toBeFalse();
    } finally {
        @\unlink($file);
    }
});

test('_ext_opt_in returns false when extra.phasync.ext is not set', function () {
    $file = \tempnam(\sys_get_temp_dir(), 'phasync-test-');
    try {
        \file_put_contents($file, '{"name": "test"}');
        expect(\phasync\_ext_opt_in($file))->toBeFalse();
    } finally {
        @\unlink($file);
    }
});

test('_ext_opt_in returns false when extra.phasync.ext is not exactly true', function () {
    $file = \tempnam(\sys_get_temp_dir(), 'phasync-test-');
    try {
        \file_put_contents($file, '{"extra": {"phasync": {"ext": 1}}}');
        expect(\phasync\_ext_opt_in($file))->toBeFalse();

        \file_put_contents($file, '{"extra": {"phasync": {"ext": "true"}}}');
        expect(\phasync\_ext_opt_in($file))->toBeFalse();

        \file_put_contents($file, '{"extra": {"phasync": {"ext": null}}}');
        expect(\phasync\_ext_opt_in($file))->toBeFalse();
    } finally {
        @\unlink($file);
    }
});

test('_ext_opt_in returns true when extra.phasync.ext is exactly true', function () {
    $file = \tempnam(\sys_get_temp_dir(), 'phasync-test-');
    try {
        \file_put_contents($file, '{"extra": {"phasync": {"ext": true}}}');
        expect(\phasync\_ext_opt_in($file))->toBeTrue();
    } finally {
        @\unlink($file);
    }
});

test('_ext_opt_in handles missing phasync key gracefully', function () {
    $file = \tempnam(\sys_get_temp_dir(), 'phasync-test-');
    try {
        \file_put_contents($file, '{"extra": {"other": {"ext": true}}}');
        expect(\phasync\_ext_opt_in($file))->toBeFalse();
    } finally {
        @\unlink($file);
    }
});

test('_ext_opt_in handles missing extra key gracefully', function () {
    $file = \tempnam(\sys_get_temp_dir(), 'phasync-test-');
    try {
        \file_put_contents($file, '{"name": "test"}');
        expect(\phasync\_ext_opt_in($file))->toBeFalse();
    } finally {
        @\unlink($file);
    }
});

test('try_enable_ext returns boolean', function () {
    $result = \phasync\try_enable_ext();
    expect(\is_bool($result))->toBeTrue();
});
