<?php

namespace phasync\Context;

use phasync\CancelledException;
use phasync\Debug;

/**
 * All coroutines are associated with an instance of ContextInterface.
 * If no ContextInterface implementation is provided when using run to
 * launch a coroutine, the coroutine will inherit the parent coroutines'
 * context. The root coroutine will use an instance of this class.
 */
final class ServiceContext implements ContextInterface
{
    use ContextTrait;

    /** PHP is shutting down, after exit() or the end of the script: see setContextException(). */
    private static ?bool $exiting = null;

    public function activate(): void
    {
        $this->activated = true;
        if (null === self::$exiting) {
            self::$exiting = false;
            \register_shutdown_function(static function () { self::$exiting = true; });
        }
    }

    public function setContextException(\Throwable $exception): void
    {
        // Shutdown destroys the services still waiting, which cancels what they wait for: the
        // process ending, not a failed service
        if (self::$exiting && $exception instanceof CancelledException) {
            return;
        }
        \fwrite(\STDERR, "ERROR IN SERVICE CONTEXT:\n=====================================\n" . $exception . "\n=====================================\nTHIS IS A FATAL ERROR. ALWAYS HANDLE EXCEPTIONS IN SERVICES\n");
        foreach ($this->getFibers() as $fiber => $void) {
            \fwrite(\STDERR, Debug::getDebugInfo($fiber) . "\n");
        }
    }
}
