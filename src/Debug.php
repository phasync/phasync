<?php
namespace phasync;

use Closure;
use Fiber;
use ReflectionFiber;
use ReflectionFunction;

final class Debug {

    /**
     * Return a debug string for various objects.
     * 
     * @param mixed $subject 
     * @return string 
     */
    public static function getDebugInfo($subject): string {
        if ($subject instanceof Fiber) {
            // Gather information about the Fiber
            $rf = new ReflectionFiber($subject);

            $status = match (true) {
                $subject->isTerminated() => 'terminated',
                $subject->isSuspended() => 'suspended at '.$rf->getExecutingFile()."(".$rf->getExecutingLine() . ")",
                $subject->isRunning() => 'running',
                default => 'unknown',
            };

            return sprintf("Fiber%d(%s, %s)", \spl_object_id($subject), $status, $subject->isTerminated() ? 'NULL' : self::getDebugInfo($rf->getCallable()));
        } elseif ($subject instanceof Closure) {
            // Use ReflectionFunction to get information about the Closure
            $ref = new ReflectionFunction($subject);
            $startLine = $ref->getStartLine();
            $endLine = $ref->getEndLine();
            $filename = $ref->getFileName();
    
            return sprintf(
                "Closure%d(%s(%d))",
                \spl_object_id($subject),
                $filename ?: 'unknown file',
                $startLine
            );
        } else {
            return "Unsupported subject type (" . \get_debug_type($subject) . ").";
        }
    }}