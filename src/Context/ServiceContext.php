<?php

namespace phasync\Context;

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

    public function activate(): void
    {
        $this->activated = true;
    }

    public function setContextException(\Throwable $exception): void
    {
        \fwrite(STDERR, "ERROR IN SERVICE CONTEXT:\n=====================================\n" . $exception . "\n=====================================\nTHIS IS A FATAL ERROR. ALWAYS HANDLE EXCEPTIONS IN SERVICES\n");
        foreach ($this->getFibers() as $fiber => $void) {
            \fwrite(STDERR, Debug::getDebugInfo($fiber) . "\n");
        }
    }
}
