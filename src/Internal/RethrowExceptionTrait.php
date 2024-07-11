<?php

namespace phasync\Internal;

use Exception;

trait RethrowExceptionTrait
{
    /**
     * This function ensures that exceptions appear to be thrown from the
     * right place even if they are thrown by phasync via the Fiber API.
     *
     * @internal
     *
     * @throws \ReflectionException
     */
    public function rebuildStackTrace(): void
    {
        return;
        // Get the current stack trace
        $finalTrace = [];
        $file       = null;
        $line       = null;
        $capturing  = false;
        $baseDir    = \dirname(__DIR__, 2);
        foreach (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $trace) {
            if (!$capturing) {
                if (!empty($trace['file']) && (\str_starts_with($trace['file'], $baseDir . \DIRECTORY_SEPARATOR . 'src') || $trace['file'] === $baseDir . \DIRECTORY_SEPARATOR . 'phasync.php')) {
                    continue;
                }
                if (empty($trace['file'])) {
                    continue;
                }
                $capturing = true;
                $file      = $trace['file'];
                $line      = $trace['line'];
                continue;
            }
            $finalTrace[] = $trace;
        }

        // Get reflection of the current exception
        $reflection = new \ReflectionClass(\Exception::class);

        // Update the stack trace
        $traceProperty = $reflection->getProperty('trace');
        $traceProperty->setAccessible(true);
        $traceProperty->setValue($this, $finalTrace);

        // Update the file and line where the exception was rethrown
        $fileProperty = $reflection->getProperty('file');
        $fileProperty->setAccessible(true);
        $fileProperty->setValue($this, $file);

        $lineProperty = $reflection->getProperty('line');
        $lineProperty->setAccessible(true);
        $lineProperty->setValue($this, $line);
    }
}
