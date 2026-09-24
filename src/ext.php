<?php

namespace phasync;

/**
 * Optional integration with the phasync C extension (phasync/phasync-ext), which is never
 * required. phasync works correctly with or without it -- this file only offers a safe way
 * to opportunistically load it if the application wants it and it happens to be available.
 */

/**
 * Try to make the phasync C extension active, delegating as much as possible to
 * phasync\ext\ensure_loaded() -- without requiring the extension to be installed at all.
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
 *  - Not available for reasons outside anyone's control right now (phasync/phasync-ext
 *    isn't installed, no prebuilt binary matches this platform, no re-exec primitive is
 *    available) -- a normal, silent `false`, same as "the extension doesn't exist".
 *  - Called from a SAPI where auto-loading can never work (fpm, mod_php, ...) while the
 *    extension isn't already active via php.ini -- a real misconfiguration, not a "maybe
 *    later": ensure_loaded() itself throws for this, and that exception is deliberately
 *    let through rather than swallowed, so the developer is told loudly rather than left
 *    wondering why nothing sped up. If the extension IS already active via php.ini for
 *    that SAPI, this still returns true, no exception -- it works there too, harmlessly.
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
    if (!\function_exists('phasync\\ext\\ensure_loaded')) {
        return false; // phasync/phasync-ext is not installed
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
