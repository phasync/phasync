<?php

/*
 * Tests for phasync\try_enable_ext() -- the probe that opportunistically loads the optional
 * phasync C extension (bundled in release packages under ext/). phasync must behave identically whether it
 * returns true or false. It intentionally CAN throw, but only for one specific case (called
 * from a non-CLI SAPI without the extension already active via php.ini) -- not exercised
 * here, since that needs a non-CLI SAPI binary (php-cgi, php-fpm) this environment doesn't
 * have, and PHP_SAPI can't be faked within the same process. The logic itself is a single,
 * narrow branch (see src/ext.php) delegating the actual SAPI check to
 * phasync\ext\ensure_loaded(); every other case IS covered below.
 */

test('returns true immediately when the extension is already active, without needing phasync-ext installed', function () {
    if (!\extension_loaded('phasync')) {
        $this->markTestSkipped('phasync extension not loaded (run with -d extension=<path to phasync-ext build>)');
    }

    expect(\phasync\try_enable_ext())->toBeTrue();
});

test('returns false when the extension is not active and no binary is found, without throwing on CLI', function () {
    if (\extension_loaded('phasync')) {
        $this->markTestSkipped('phasync extension is loaded in this process');
    }

    $env = \getenv('PHASYNC_EXT_SO');
    \putenv('PHASYNC_EXT_SO=/nonexistent/path/does-not-exist.so');
    try {
        expect(\phasync\try_enable_ext())->toBeFalse();
    } finally {
        \putenv(false === $env ? 'PHASYNC_EXT_SO' : 'PHASYNC_EXT_SO=' . $env);
    }
});

test('actually loads the extension via a real re-exec, when PHASYNC_EXT_SO points at a binary', function () {
    // The genuinely interesting path (a real, successful re-exec) can't be observed from
    // inside the calling process -- a successful re-exec REPLACES this process, it doesn't
    // return to it. So this drives it from a child process and inspects what THAT process
    // sees after the fact.
    $devBuild = \getenv('PHASYNC_EXT_SO');
    if (!\is_string($devBuild) || !\is_file($devBuild) || \extension_loaded('phasync')) {
        $this->markTestSkipped('needs PHASYNC_EXT_SO pointing at a matching binary, and the extension must not already be loaded');
    }

    $script = <<<'PHP'
        <?php
        require %s;
        $enabled = \phasync\try_enable_ext();
        echo $enabled ? 'true' : 'false';
        echo ',';
        echo \extension_loaded('phasync') ? 'true' : 'false';
        PHP;
    $script = \sprintf($script, \var_export(\dirname(__DIR__) . '/vendor/autoload.php', true));
    $file   = \tempnam(\sys_get_temp_dir(), 'phasync-ext-test-');
    \file_put_contents($file, $script);

    try {
        $output = null;
        $exit   = null;
        \exec(\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($file) . ' 2>&1', $output, $exit);
        expect($exit)->toBe(0);
        expect(\implode("\n", $output))->toBe('true,true');
    } finally {
        @\unlink($file);
    }
});

test('ext_enabled reads the opt-in from composer.json "extra"', function () {
    $file = \tempnam(\sys_get_temp_dir(), 'phasync-composer-');
    try {
        \file_put_contents($file, '{"extra": {"phasync": {"ext": true}}}');
        expect(\phasync\_ext_opt_in($file))->toBeTrue();

        \file_put_contents($file, '{"extra": {"discovery": {}}}');
        expect(\phasync\_ext_opt_in($file))->toBeFalse();

        \file_put_contents($file, '{"extra": {"phasync": {"ext": "yes"');
        expect(\phasync\_ext_opt_in($file))->toBeFalse();

        expect(\phasync\_ext_opt_in($file . '.missing'))->toBeFalse();
    } finally {
        @\unlink($file);
    }
});

test('ext_enabled is false for this repository, which does not opt in', function () {
    expect(\phasync\ext_enabled())->toBeFalse();
});

test('_abi_key has the form <major.minor>-<nts|zts>-<arch>-<glibc|musl>', function () {
    expect(\phasync\ext\_abi_key())->toMatch('/^\d+\.\d+-(nts|zts)-[\w]+-(glibc|musl)$/');
});

test('_resolve_so prefers PHASYNC_EXT_SO, then ext/phasync-<abi>.so, else null', function () {
    $env     = \getenv('PHASYNC_EXT_SO');
    $so      = \dirname(__DIR__) . '/ext/phasync-' . \phasync\ext\_abi_key() . '.so';
    $dir     = \dirname($so);
    $madeDir = !\is_dir($dir);
    $madeSo  = false;
    try {
        \putenv('PHASYNC_EXT_SO=/some/where.so');
        expect(\phasync\ext\_resolve_so())->toBe('/some/where.so');

        \putenv('PHASYNC_EXT_SO');
        if (!\is_file($so)) {
            expect(\phasync\ext\_resolve_so())->toBeNull();
            $madeDir && \mkdir($dir);
            \touch($so);
            $madeSo = true;
        }
        expect(\phasync\ext\_resolve_so())->toBe($so);
    } finally {
        $madeSo && @\unlink($so);
        $madeDir && @\rmdir($dir);
        \putenv(false === $env ? 'PHASYNC_EXT_SO' : 'PHASYNC_EXT_SO=' . $env);
    }
});
