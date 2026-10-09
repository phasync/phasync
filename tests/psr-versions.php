<?php

/*
 * php tests/psr-versions.php
 *
 * Loads every class of src/ against each major version of the psr/* packages this package's
 * composer.json allows, as an application's Composer may pick any of them: run N takes the Nth
 * allowed major of each package (its last for a package that allows fewer), so the first run is
 * every package at the lowest release it allows, the last at its highest major. A class whose signatures one
 * version's interfaces don't accept fails to load, and the run fails. Needs composer and network
 * (or composer's cache).
 */

$root     = \dirname(__DIR__);
$require  = \json_decode(\file_get_contents("$root/composer.json"), true)['require'];
$packages = [];
foreach ($require as $name => $constraint) {
    if (\str_starts_with($name, 'psr/')) {
        \preg_match_all('/\^(\d+)/', $constraint, $m);
        $packages[$name] = \array_map('intval', $m[1]);
    }
}
$classes = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/src")) as $file) {
    if ('php' !== $file->getExtension()) {
        continue;
    }
    $namespace = '';
    $tokens    = PhpToken::tokenize(\file_get_contents($file->getPathname()));
    foreach ($tokens as $i => $token) {
        if ($token->is(\T_NAMESPACE)) {
            $namespace = '';
            for ($j = $i + 1; !\in_array($tokens[$j]->text, [';', '{'], true); ++$j) {
                $namespace .= \trim($tokens[$j]->text);
            }
        } elseif ($token->is([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM]) && !$tokens[$i - 1]->is(\T_DOUBLE_COLON) && !$tokens[$i - 2]->is(\T_NEW)) {
            for ($j = $i + 1; $tokens[$j]->is(\T_WHITESPACE); ++$j) {
            }
            if ($tokens[$j]->is(\T_STRING)) {
                $classes[] = \ltrim("$namespace\\{$tokens[$j]->text}", '\\');
            }
        }
    }
}

$runs   = \max(\array_map('count', $packages));
$failed = false;
for ($run = 0; $run < $runs; ++$run) {
    $picked = [];
    foreach ($packages as $name => $majors) {
        $picked[$name] = $majors[\min($run, \count($majors) - 1)];
    }
    $label = \implode(', ', \array_map(fn ($n, $v) => "$n $v", \array_keys($picked), $picked));
    $dir   = \sys_get_temp_dir() . '/psr-versions-' . \getmypid() . "-$run";
    \mkdir($dir);
    $args = \implode(' ', \array_map(fn ($n, $v) => \escapeshellarg("$n:^$v.0"), \array_keys($picked), $picked));
    // The first run at the lowest release of each lowest major, the others at the newest
    \exec('composer require --no-interaction --quiet --working-dir=' . \escapeshellarg($dir) . ($run ? '' : ' --prefer-lowest') . " $args 2>&1", $out, $code);
    if (0 !== $code) {
        echo "FAIL  $label: composer could not install it\n", \implode("\n", $out), "\n";
        $failed = true;
        continue;
    }
    // This package's autoloader first: Composer prepends each later one, so the picked versions win
    $check = '<?php require ' . \var_export("$root/vendor/autoload.php", true) . '; require ' . \var_export("$dir/vendor/autoload.php", true) . ';'
        . 'foreach (' . \var_export($classes, true) . ' as $c) { class_exists($c) || interface_exists($c) || trait_exists($c) || enum_exists($c) || throw new Error("$c not found"); }'
        // and the PSR interfaces they implement are the picked versions, not this package's own
        . 'foreach (get_declared_interfaces() as $i) { if (str_starts_with($i, "Psr\\\\") && !str_starts_with((new ReflectionClass($i))->getFileName(), ' . \var_export("$dir/", true) . ')) { throw new Error("$i came from this package\'s vendor/"); } }'
        . 'echo "ok";';
    \file_put_contents("$dir/check.php", $check);
    $result = \shell_exec(\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg("$dir/check.php") . ' 2>&1');
    \exec('rm -rf ' . \escapeshellarg($dir));
    if ('ok' !== $result) {
        echo "FAIL  $label:\n$result\n";
        $failed = true;
        continue;
    }
    echo "ok    $label (" . \count($classes) . " classes)\n";
}
exit($failed ? 1 : 0);
