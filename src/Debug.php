<?php
namespace phasync;

use Closure;
use Fiber;
use ReflectionFiber;
use ReflectionFunction;

final class Debug {

    /**
     * Format a binary string to be printable, for debugging binary protocols
     * and other data. The characters that can be printed will be kept, while
     * binary data will be enclosed in ⟪01FA6B⟫, and when the length 
     * 
     * @param string $binary The binary data to print.
     * @param bool $withLength Should byte sequences longer than 8 bytes ble chunked?
     * @return string 
     */
    public static function binaryString(string $binary, bool $withLength=false): string {
        $re = '/([\x00-\x1F\x80-\xFF]+([^\x00-\x1F\x80-\xFF]{1,2}[\x00-\x1F\x80-\xFF]+)*)/';

        return \preg_replace_callback($re, subject: $binary, callback: function($matches) use ($withLength) {
            $string = $matches[0];
            $chunked = \implode("∙", \str_split(\bin2hex($string), 8));
            $length = \strlen($string);
            if ($withLength && $length > 8) {
                return '⟪'. $length . '|' . $chunked . '⟫';
            } else {
                return '⟪'. $chunked . '⟫';
            }
        });
    }

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
        } elseif (is_object($subject)) {
            return \get_class($subject) . \spl_object_id($subject);
        } else {
            return "Unsupported subject type (" . \get_debug_type($subject) . ").";
        }
    }}
