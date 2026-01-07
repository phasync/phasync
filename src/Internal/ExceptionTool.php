<?php

namespace phasync\Internal;

use Exception;

final class ExceptionTool
{
    /**
     * Updates an exception trace so that the exception appears to have been
     * caused by the calling function.
     *
     * @param string $filter If provided, will remove until the filter (function, class, or file) is not in the trace
     *
     * @throws \ReflectionException
     */
    public static function popTrace(\Throwable $exception, ?string $filter = null): \Throwable
    {
        $rc     = new \ReflectionClass(\Exception::class);

        $rTrace = $rc->getProperty('trace');
        $rFile  = $rc->getProperty('file');
        $rLine  = $rc->getProperty('line');

        $trace = $rTrace->getValue($exception);

        $top = null;

        if (null !== $filter) {
            do {
                $found = false;
                foreach ($trace as $t) {
                    if ($filter === ($t['file'] ?? null)
                        || $filter === ($t['class'] ?? null)
                        || $filter === ($t['function'] ?? null)) {
                        $found = true;
                        break;
                    }
                }
                if ($found) {
                    $top = \array_shift($trace);
                } elseif (false === $top) {
                    return $exception;
                }
            } while ($found);
        } else {
            $top = \array_shift($trace);
        }

        if (null === $top) {
            return $exception;
        }

        $rTrace->setValue($exception, $trace);

        $rFile->setValue($exception, $top['file']);
        $rLine->setValue($exception, $top['line']);

        return $exception;
    }
}
