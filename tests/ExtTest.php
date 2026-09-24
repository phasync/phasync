<?php

/*
 * Tests for phasync\try_enable_ext() -- the probe that opportunistically loads the optional
 * phasync C extension (phasync/phasync-ext). phasync must behave identically whether it
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

test('returns false when the extension is not active and phasync/phasync-ext is not installed', function () {
    if (\extension_loaded('phasync')) {
        $this->markTestSkipped('phasync extension is loaded in this process; this exercises the not-installed case');
    }
    if (\function_exists('phasync\\ext\\ensure_loaded')) {
        $this->markTestSkipped('phasync/phasync-ext appears to be installed in this environment');
    }

    expect(\phasync\try_enable_ext())->toBeFalse();
});

test('on CLI, a missing binary is silent (false), not thrown', function () {
    // Simulates phasync/phasync-ext being installed (its composer-autoloaded shim declared)
    // without the extension active and without a resolvable binary -- ensure_loaded() itself
    // documents throwing \RuntimeException for exactly this. On CLI that's an environment
    // limitation, not a misuse, so try_enable_ext() swallows it (unlike the non-CLI case,
    // which is a real misconfiguration and is deliberately let through -- see src/ext.php).
    $extBootstrap = \dirname(__DIR__, 2) . '/phasync-ext/phasync-ext.php';
    if (!\is_file($extBootstrap) || \extension_loaded('phasync')) {
        $this->markTestSkipped('needs the sibling phasync-ext checkout, and the extension must not already be loaded');
    }

    $script = <<<'PHP'
        <?php
        require %s;
        require %s;
        putenv('PHASYNC_EXT_SO=/nonexistent/path/does-not-exist.so');
        var_export(\phasync\try_enable_ext());
        PHP;
    $script = \sprintf($script, \var_export($extBootstrap, true), \var_export(\dirname(__DIR__) . '/src/ext.php', true));
    $file   = \tempnam(\sys_get_temp_dir(), 'phasync-ext-test-');
    \file_put_contents($file, $script);

    try {
        $output = null;
        $exit   = null;
        \exec(\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($file) . ' 2>&1', $output, $exit);
        expect($exit)->toBe(0);
        expect(\implode("\n", $output))->toBe('false');
    } finally {
        @\unlink($file);
    }
});

test('actually loads the extension via a real re-exec, when phasync-ext\'s dev build is available', function () {
    // The genuinely interesting path (a real, successful re-exec) can't be observed from
    // inside the calling process -- a successful re-exec REPLACES this process, it doesn't
    // return to it. So this drives it from a child process and inspects what THAT process
    // sees after the fact.
    $extBootstrap = \dirname(__DIR__, 2) . '/phasync-ext/phasync-ext.php';
    $devBuild     = \dirname(__DIR__, 2) . '/phasync-ext/modules/phasync.so';
    if (!\is_file($extBootstrap) || !\is_file($devBuild) || \extension_loaded('phasync')) {
        $this->markTestSkipped('needs the sibling phasync-ext checkout with a dev build, and the extension must not already be loaded');
    }

    $script = <<<'PHP'
        <?php
        require %s;
        require %s;
        $enabled = \phasync\try_enable_ext();
        echo $enabled ? 'true' : 'false';
        echo ',';
        echo \extension_loaded('phasync') ? 'true' : 'false';
        PHP;
    $script = \sprintf($script, \var_export($extBootstrap, true), \var_export(\dirname(__DIR__) . '/src/ext.php', true));
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
