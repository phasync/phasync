<?php

/*
 * The characterization tests pin behaviour that depends on process-wide state: the driver
 * singleton (deadline checks, idle timing, GC timing), the preempt clock, the default
 * timeout, channel bookkeeping and the GC switch. Older test files also change some of this
 * while they are loaded, so the ambient state depends on load order. Without a reset the
 * results depend on which test ran before, so every characterization test starts from, and
 * leaves behind, the same known state: no leftover coroutines or channel bookkeeping, and a
 * driver that is in the steady state of a loop that is already running (see below).
 *
 * The reset only uses reflection on statics and the driver; nothing under src/ is changed.
 */
if (!\function_exists('phasyncResetProcessState')) {
    function phasyncResetProcessState(): void
    {
        $set = static function (string $class, string $property, mixed $value): void {
            (new ReflectionProperty($class, $property))->setValue(null, $value);
        };

        // Let destructors of objects left over from the previous test run now, not during
        // a later test (a leftover exception holder or channel end would log or throw there).
        \gc_collect_cycles();

        // Leftover queues and flags go with the old driver.
        $set('phasync', 'driver', null);
        $set('phasync', 'runDepth', 0);
        $set('phasync', 'lastPreemptTime', 0);
        $set('phasync', 'preemptInterval', phasync::DEFAULT_PREEMPT_INTERVAL);
        $set('phasync', 'onEnterCallbacks', []);
        $set('phasync', 'onExitCallbacks', []);
        $set('phasync', 'promiseHandlerFunction', null);
        phasync::setDefaultTimeout(phasync::DEFAULT_TIMEOUT);

        $set('phasync\Internal\Channel', 'blockedCount', 0);

        // The selector pools keep objects with stale fields between uses (see the SEL-6 test
        // about a pooled ClosureSelector), so which instance a select() gets changes its result.
        foreach (['phasync\Internal\Selector', 'phasync\Internal\FiberSelector', 'phasync\Internal\ClosureSelector'] as $class) {
            $set($class, 'pool', []);
            $set($class, 'instanceCount', 0);
        }

        \gc_enable();

        // Steady state: a driver that has just done its periodic maintenance. A brand new
        // driver has lastTimeoutCheck, lastIdleRun and lastGarbageCollect at 0, so its first
        // tick checks timeouts, raises the idle flag and runs the cycle collector at once.
        // Most pinned behaviour (zero timeouts, idle(), cycles surviving unset()) is about a
        // loop that is already running, where those happen at most every 0.1 s, 1 s and 0.5 s.
        $driver = (new ReflectionMethod('phasync', 'getDriver'))->invoke(null);
        $now    = \microtime(true);
        foreach (['lastTimeoutCheck', 'lastIdleRun', 'lastGarbageCollect'] as $property) {
            (new ReflectionProperty($driver, $property))->setValue($driver, $now);
        }
    }
}

if (!\function_exists('heldFlagWait')) {
    /**
     * Waits on a flag that this function keeps referenced for the whole wait, so the wait ends
     * only by timeout, cancellation or a raise. Waiting on a flag that nothing else references,
     * `awaitFlag(new stdClass())`, is undefined behaviour (docs/SEMANTICS.md, FLG-1).
     */
    function heldFlagWait(float $timeout = \PHP_FLOAT_MAX): void
    {
        $flag = new stdClass();
        try {
            phasync::awaitFlag($flag, $timeout);
        } finally {
            unset($flag);
        }
    }
}

uses()->beforeEach(function () {
    phasyncResetProcessState();
})->afterEach(function () {
    phasyncResetProcessState();
})->in('Characterization');
