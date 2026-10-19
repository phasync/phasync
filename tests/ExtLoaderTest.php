<?php

/*
 * phasync\ext\ensure_loaded(): the loader that re-executes the CLI process with the phasync
 * extension. A successful re-exec replaces the process, so everything that depends on the
 * loader state runs in a child PHP started with -n (no ini, no extension) and an environment of
 * its own; the real binary (PHASYNC_EXT_SO, else the dev build) is only loaded in those children
 * and is skipped over when it is missing. Nothing is downloaded and no real binary is touched.
 */

$extSo = static function (): ?string {
    $so = \getenv('PHASYNC_EXT_SO');

    return \is_string($so) && \is_file($so) ? $so : null;
};

/**
 * Run $body as a PHP script in a child `php -n` and return [exit code, stdout and stderr].
 *
 * @param list<string>          $phpArgs interpreter arguments before the script
 * @param array<string, string> $env     environment of the child, on top of this process's own
 *                                       without the loader's and the extension scan dir variables
 * @param list<string>          $argv    arguments for the script
 */
$runChild = static function (string $body, array $phpArgs = [], array $env = [], array $argv = []): array {
    $file = \tempnam(\sys_get_temp_dir(), 'phasync-loader-');
    \file_put_contents($file, "<?php\nrequire " . \var_export(\dirname(__DIR__) . '/src/ext_loader.php', true) . ";\n" . $body);
    $base = \getenv();
    unset($base['PHASYNC_EXT_REEXEC'], $base['PHASYNC_EXT_SO'], $base['PHP_INI_SCAN_DIR']);

    $command = [\PHP_BINARY, '-n', ...$phpArgs, $file, ...$argv];
    $process = \proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env + $base);
    $output  = \stream_get_contents($pipes[1]);
    \fclose($pipes[1]);
    $exit = \proc_close($process);
    @\unlink($file);

    return [$exit, $output];
};

/** A child script's try/catch around ensure_loaded(): prints the exception message, or "loaded". */
$ensure = static fn (string $args = ''): string => <<<PHP
    try {
        \\phasync\\ext\\ensure_loaded({$args});
        echo 'returned';
    } catch (\\RuntimeException \$e) {
        echo \$e->getMessage();
    }
    PHP;

test('_needs_php83() names the running PHP and says phasync works without the extension', function () {
    $message = \phasync\ext\_needs_php83();

    expect($message)->toContain('needs PHP 8.3 or later');
    expect($message)->toContain(\PHP_VERSION);
    expect($message)->toContain('phasync itself runs on this PHP without it');
});

test('ensure_loaded() returns at once, without touching the process, when the extension is already loaded', function () {
    if (!\extension_loaded('phasync')) {
        $this->markTestSkipped('needs the phasync extension loaded (run with the extension)');
    }

    \phasync\ext\ensure_loaded('/nonexistent/phasync.so');

    expect(\extension_loaded('phasync'))->toBeTrue();
});

test('_original_args() is the interpreter\'s own command line without argv[0]', function () {
    if (!\is_readable('/proc/self/cmdline')) {
        $this->markTestSkipped('needs /proc/self/cmdline (Linux)');
    }

    $parts = \explode("\0", \rtrim(\file_get_contents('/proc/self/cmdline'), "\0"));
    \array_shift($parts);

    expect(\phasync\ext\_original_args())->toBe($parts);
    expect($parts)->not->toBe([]);
});

test('_original_args() in a child keeps the interpreter flags, the script and its arguments', function () use ($runChild) {
    [$exit, $output] = $runChild('echo json_encode(\\phasync\\ext\\_original_args());', ['-d', 'memory_limit=77M'], [], ['one', 'two words']);

    expect($exit)->toBe(0);
    $args = \json_decode($output, true);
    expect(\array_slice($args, 0, 3))->toBe(['-n', '-d', 'memory_limit=77M']);
    expect(\array_slice($args, 4))->toBe(['one', 'two words']);
});

test('_original_args() falls back to the script and its arguments where /proc is out of reach', function () use ($runChild) {
    // open_basedir keeps /proc out of reach, as on a platform without it
    [$exit, $output] = $runChild(
        'echo json_encode([\\phasync\\ext\\_original_args(), $_SERVER["argv"]]);',
        ['-d', 'open_basedir=' . \sys_get_temp_dir() . ':' . \dirname(__DIR__) . '/src'],
        [],
        ['one', 'two words']
    );

    expect($exit)->toBe(0);
    [$args, $argv] = \json_decode($output, true);
    expect($args)->toBe($argv);
    expect(\array_slice($args, 1))->toBe(['one', 'two words']);
});

test('current_fiber() is Fiber::getCurrent() without the extension\'s virtualize()', function () {
    expect(\phasync\ext\current_fiber())->toBeNull();

    $inside = null;
    $fiber  = new Fiber(function () use (&$inside) {
        $inside = \phasync\ext\current_fiber();
    });
    $fiber->start();

    expect($inside)->toBe($fiber);
});

test('ensure_loaded() refuses to re-exec twice: a child that was already re-executed and still has no extension throws', function () use ($runChild, $ensure) {
    [$exit, $output] = $runChild($ensure('\'/some/where.so\''), [], ['PHASYNC_EXT_REEXEC' => '1']);

    expect($exit)->toBe(0);
    expect($output)->toContain('re-exec did not load the phasync extension');
    expect($output)->toContain(\phasync\ext\_abi_key());
})->skip(\PHP_VERSION_ID < 80300, 'ensure_loaded() stops at its PHP 8.3 check on older PHP');

test('ensure_loaded() throws instructively when no binary can be found: explicit path, PHASYNC_EXT_SO, or nothing', function () use ($runChild, $ensure) {
    $dir = \sys_get_temp_dir() . '/phasync-loader-missing-' . \bin2hex(\random_bytes(4));

    [, $explicit] = $runChild($ensure(\var_export($dir . '/explicit.so', true)));
    [, $env]      = $runChild($ensure(), [], ['PHASYNC_EXT_SO' => $dir . '/env.so']);
    [, $empty]    = $runChild($ensure(), [], ['PHASYNC_EXT_SO' => '']);

    // a checkout with the release binaries bundled in ext/ finds one without PHASYNC_EXT_SO
    $outputs = \is_file(\dirname(__DIR__) . '/ext/phasync-' . \phasync\ext\_abi_key() . '.so') ? [$explicit, $env] : [$explicit, $env, $empty];
    foreach ($outputs as $output) {
        expect($output)->toContain('no phasync binary found for this platform (' . \phasync\ext\_abi_key() . ')');
        expect($output)->toContain('Set PHASYNC_EXT_SO');
    }
})->skip(\PHP_VERSION_ID < 80300, 'ensure_loaded() stops at its PHP 8.3 check on older PHP');

test('an explicit path wins over PHASYNC_EXT_SO: only the explicit one is looked for', function () use ($runChild, $ensure) {
    $present = \tempnam(\sys_get_temp_dir(), 'phasync-loader-so-');
    try {
        // The env names a missing file, the explicit one exists: the loader gets as far as re-exec,
        // which neither primitive can do here
        [, $output] = $runChild(
            $ensure(\var_export($present, true)),
            ['-d', 'disable_functions=pcntl_exec'],
            ['PHASYNC_EXT_SO' => '/nonexistent/env.so']
        );
    } finally {
        @\unlink($present);
    }

    expect($output)->toContain('cannot re-exec to load the extension');
    expect($output)->toContain($present);
})->skip(\PHP_VERSION_ID < 80300, 'ensure_loaded() stops at its PHP 8.3 check on older PHP');

test('without pcntl_exec() and FFI the loader says how to start PHP with the extension, and has marked the environment for the loop guard', function () use ($runChild) {
    $present = \tempnam(\sys_get_temp_dir(), 'phasync-loader-so-');
    try {
        [$exit, $output] = $runChild(
            'try { \\phasync\\ext\\ensure_loaded(); } catch (\\RuntimeException $e) { echo $e->getMessage(), "|", getenv("PHASYNC_EXT_REEXEC"); }',
            ['-d', 'disable_functions=pcntl_exec'],
            ['PHASYNC_EXT_SO' => $present]
        );
    } finally {
        @\unlink($present);
    }

    expect($exit)->toBe(0);
    expect($output)->toContain('neither pcntl_exec() nor FFI execv() is available');
    expect($output)->toContain('php -d extension=' . $present);
    expect($output)->toEndWith('|1');
})->skip(\PHP_VERSION_ID < 80300, 'ensure_loaded() stops at its PHP 8.3 check on older PHP');

test('with FFI present but disabled, _ffi_execv() reports false and the loader falls through to its error', function () use ($runChild) {
    [$probe] = $runChild('exit(extension_loaded("ffi") ? 0 : 1);', ['-d', 'extension=ffi']);
    if (0 !== $probe) {
        $this->markTestSkipped('needs the ffi extension to load into a child PHP');
    }

    [$exit, $output] = $runChild(
        'var_dump(\\phasync\\ext\\_ffi_execv([PHP_BINARY, "-r", "echo 1;"]));',
        ['-d', 'extension=ffi', '-d', 'ffi.enable=0']
    );

    expect($exit)->toBe(0);
    expect(\trim($output))->toBe('bool(false)');
});

test('ensure_loaded() re-executes through pcntl_exec(), keeping the interpreter flags, script and arguments', function () use ($runChild, $extSo) {
    $so = $extSo();
    if (null === $so || !\function_exists('pcntl_exec')) {
        $this->markTestSkipped('needs a phasync binary (PHASYNC_EXT_SO or the dev build) and pcntl');
    }

    [$exit, $output] = $runChild(
        '\\phasync\\ext\\ensure_loaded(' . \var_export($so, true) . ');'
        . 'echo json_encode([extension_loaded("phasync"), ini_get("memory_limit"), getenv("PHASYNC_EXT_REEXEC"), array_slice($argv, 1)]);',
        ['-d', 'memory_limit=77M'],
        // the explicit path is what is loaded, not this
        ['PHASYNC_EXT_SO' => '/nonexistent/env.so'],
        ['one', 'two words']
    );

    expect($exit)->toBe(0);
    expect(\json_decode($output, true))->toBe([true, '77M', '1', ['one', 'two words']]);
})->skip(\PHP_VERSION_ID < 80300, 'ensure_loaded() stops at its PHP 8.3 check on older PHP');

test('ensure_loaded() re-executes through FFI execv() when pcntl_exec() is unavailable', function () use ($runChild, $extSo) {
    $so      = $extSo();
    [$probe] = $runChild('exit(extension_loaded("ffi") ? 0 : 1);', ['-d', 'extension=ffi']);
    if (null === $so || 0 !== $probe) {
        $this->markTestSkipped('needs a phasync binary (PHASYNC_EXT_SO or the dev build) and the ffi extension');
    }

    [$exit, $output] = $runChild(
        '\\phasync\\ext\\ensure_loaded();'
        . 'echo json_encode([extension_loaded("phasync"), getenv("PHASYNC_EXT_REEXEC"), array_slice($argv, 1)]);',
        // FFI::new() and FFI::cast() called statically are deprecated since PHP 8.5, which the loader does
        ['-d', 'extension=ffi', '-d', 'ffi.enable=1', '-d', 'disable_functions=pcntl_exec', '-d', 'error_reporting=' . (\E_ALL & ~\E_DEPRECATED)],
        ['PHASYNC_EXT_SO' => $so],
        ['a b', 'c']
    );

    expect($exit)->toBe(0);
    expect(\json_decode($output, true))->toBe([true, '1', ['a b', 'c']]);
})->skip(\PHP_VERSION_ID < 80300, 'ensure_loaded() stops at its PHP 8.3 check on older PHP');

test('_abi_key has the form <major.minor>-<nts|zts>-<arch>-<glibc|musl|darwin>', function () {
    expect(\phasync\ext\_abi_key())->toMatch('/^\d+\.\d+-(nts|zts)-[\w]+-(glibc|musl|darwin)$/');
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

test('_version_problem() accepts an extension of the same major version, from the minimum on', function () {
    foreach (['2.0.0-dev', '2.0.0-beta1', '2.0.0', '2.7.3'] as $v) {
        expect(\phasync\ext\_version_problem($v))->toBeNull();
    }
});

test('_version_problem() names both versions and the fix when the extension is too old or too new', function () {
    expect(\phasync\ext\_version_problem('0.5.0-beta7'))
        ->toContain('0.5.0-beta7')->toContain('pie install phasync/phasync-ext');
    expect(\phasync\ext\_version_problem('3.0.0'))
        ->toContain('3.0.0')->toContain('composer update phasync/phasync');
});
