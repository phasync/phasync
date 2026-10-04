<?php

namespace phasync;

/**
 * Optional integration with the phasync C extension, which ships in the release packages
 * under ext/ and is never required. phasync works correctly with or without it -- this file
 * only offers a safe way to load it if the application wants it and a binary is available.
 */

/**
 * Whether the application opted in to the extension: the root project's composer.json has
 * `"extra": {"phasync": {"ext": true}}`. This only reports the setting; it loads nothing --
 * the caller (a CLI script, or a tool such as Swerve) decides to call try_enable_ext().
 *
 * @internal not part of the public API; may change in any release
 */
function ext_enabled(): bool
{
    static $enabled;

    return $enabled ??= _ext_opt_in(\Composer\InstalledVersions::getRootPackage()['install_path'] . '/composer.json');
}

/**
 * Read the opt-in setting from a composer.json file; false when it is unreadable or malformed.
 *
 * @internal
 */
function _ext_opt_in(string $composerJson): bool
{
    $data = \is_readable($composerJson) ? \json_decode(\file_get_contents($composerJson), true) : null;

    return ($data['extra']['phasync']['ext'] ?? null) === true;
}

/**
 * Try to make the phasync C extension active, delegating to phasync\ext\ensure_loaded().
 *
 * This only loads the extension -- it does not wire anything into phasync's scheduler.
 * phasync behaves identically whether this returns true or false; nothing in phasync core
 * assumes the extension is present.
 *
 * Loading the extension can replace the current process (see phasync\ext\ensure_loaded(),
 * which this calls): call this once, as early as possible in a CLI script, before doing any
 * work with side effects. Never call this from inside phasync::run() or other library code
 * that might run after the application has already done meaningful work -- a caller who has
 * already produced output or opened resources could lose them to the re-exec.
 *
 * Two kinds of "can't enable it" are treated differently, on purpose:
 *  - Not available for reasons outside anyone's control right now (no bundled binary for
 *    this platform, or not a release install, since dev checkouts have no ext/ binaries; no
 *    re-exec primitive is available) -- a normal, silent `false`, same as "the extension
 *    doesn't exist".
 *  - Called from a SAPI where auto-loading can never work (fpm, mod_php, ...) while the
 *    extension isn't already active via php.ini -- a real misconfiguration, not a "maybe
 *    later": ensure_loaded() itself throws for this, and that exception is deliberately
 *    let through rather than swallowed, so the developer is told loudly rather than left
 *    wondering why nothing sped up. If the extension IS already active via php.ini for
 *    that SAPI, this still returns true, no exception -- it works there too, harmlessly.
 *
 * @internal not part of the public API; may change in any release
 *
 * @throws \RuntimeException if called from a non-CLI SAPI without the extension already
 *                           active via php.ini (propagated from ensure_loaded())
 *
 * @return bool true if the extension is active (already was, or was just loaded); false if
 *              it isn't and couldn't be for a reason outside anyone's control right now
 */
function try_enable_ext(): bool
{
    if (\extension_loaded('phasync')) {
        return true; // already active -- however it got there (php.ini, -d, a prior call)
    }
    try {
        \phasync\ext\ensure_loaded(); // may re-exec the process (CLI) and never return

        return \extension_loaded('phasync');
    } catch (\RuntimeException $e) {
        if (\PHP_SAPI !== 'cli') {
            throw $e; // wrong SAPI for auto-loading, and not already active -- let it through
        }

        return false; // CLI, but no matching binary or no re-exec primitive available
    }
}
