<?php

namespace phasync\Internal;

use Exception;

final class ExceptionTool
{
    /**
     * Updates an exception trace so that the exception appears to have been
     * caused by the calling function.
     *
     * @throws \ReflectionException
     */
    public static function popTrace(\Throwable $exception): \Throwable
    {
        $rc     = new \ReflectionClass(\Exception::class);
        $rTrace = $rc->getProperty('trace');
        $rFile  = $rc->getProperty('file');
        $rLine  = $rc->getProperty('line');
        $rTrace->setAccessible(true);
        $trace = $rTrace->getValue($exception);
        $top   = \array_shift($trace);
        $rTrace->setValue($exception, $trace);
        $rFile->setValue($exception, $top['file']);
        $rLine->setValue($exception, $top['line']);

        return $exception;
    }
}
