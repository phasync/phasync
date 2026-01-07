<?php

/**
 * @codeCoverageIgnore
 */

namespace phasync;

use Closure;
use Fiber;
use ReflectionFunction;

final class Debug
{
    /**
     * Format a binary string to be printable, for debugging binary protocols
     * and other data. The characters that can be printed will be kept, while
     * binary data will be enclosed in ⟪01FA6B⟫, and when the length
     *
     * @param string $binary     the binary data to print
     * @param bool   $withLength Should byte sequences longer than 8 bytes ble chunked?
     */
    public static function binaryString(string $binary, bool $withLength=false): string
    {
        $re = '/([\x00-\x1F\x80-\xFF]+([^\x00-\x1F\x80-\xFF]{1,2}[\x00-\x1F\x80-\xFF]+)*)/';

        return \preg_replace_callback($re, subject: $binary, callback: function ($matches) use ($withLength) {
            $string  = $matches[0];
            $chunked = \implode('∙', \str_split(\bin2hex($string), 8));
            $length  = \strlen($string);
            if ($withLength && $length > 8) {
                return '⟪' . $length . '|' . $chunked . '⟫';
            }

            return '⟪' . $chunked . '⟫';
        });
    }

    /**
     * Return a debug string for various objects.
     */
    public static function getDebugInfo($subject): string
    {
        $cwd = \getcwd() . \DIRECTORY_SEPARATOR;
        if ($subject instanceof \Fiber) {
            // Gather information about the Fiber
            $rf = new \ReflectionFiber($subject);

            $status = match (true) {
                $subject->isTerminated() => 'terminated',
                $subject->isSuspended()  => 'suspended at ' . \str_replace($cwd, '', $rf->getExecutingFile()) . '(' . $rf->getExecutingLine() . ')',
                $subject->isRunning()    => 'running',
                default                  => 'unknown',
            };

            return \sprintf('Fiber%d(%s, %s)', \spl_object_id($subject), $status, $subject->isTerminated() ? 'NULL' : self::getDebugInfo($rf->getCallable()));
        } elseif ($subject instanceof \Closure) {
            // Use ReflectionFunction to get information about the Closure
            $ref       = new \ReflectionFunction($subject);
            $startLine = $ref->getStartLine();
            $endLine   = $ref->getEndLine();
            $filename  = \str_replace($cwd, '', $ref->getFileName());

            return \sprintf(
                'Closure%d(%s(%d))',
                \spl_object_id($subject),
                $filename ?: 'unknown file',
                $startLine
            );
        } elseif ($subject instanceof \WeakMap) {
            $result = 'WeakMap' . \spl_object_id($subject);
            $kvs    = ['count=' . \count($subject)];
            foreach ($subject as $k => $v) {
                $kvs[] = Debug::getDebugInfo($k) . '=>' . Debug::getDebugInfo($v);
                if (\count($kvs) > 2) {
                    break;
                }
            }

            return $result . '(' . \implode(' ', $kvs) . ')';
        } elseif (\is_object($subject)) {
            $result = \get_class($subject) . \spl_object_id($subject);
            $kvs    = [];
            if ($subject instanceof \Countable) {
                $kvs[] = 'count=' . \count($subject);
            }
            if (\count($kvs) > 0) {
                $result .= '(' . \implode(' ', $kvs) . ')';
            }

            return $result;
        } elseif (\is_array($subject)) {
            return 'array(length=' . \count($subject) . ')';
        } elseif (\is_scalar($subject)) {
            return \get_debug_type($subject) . '(' . $subject . ')';
        } elseif (null === $subject) {
            return 'NULL';
        }

        return 'Unsupported subject type (' . \get_debug_type($subject) . ').';
    }

    /**
     * Scans the entire application for possible leaks; returning static
     * variables and globals that take a lot of space.
     */
    public static function findLeaks(string|array $namespace=''): array
    {
        $candidates = [];
        $classes    = \get_declared_classes();
        $queue      = [];
        foreach ($classes as $className) {
            if (\is_string($namespace) && !\str_starts_with($className, $namespace)) {
                continue;
            } elseif (\is_array($namespace)) {
                $found = false;
                foreach ($namespace as $ns) {
                    if (\str_starts_with($className, $ns)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    continue;
                }
            }
            $rc         = new \ReflectionClass($className);
            $properties = $rc->getStaticProperties();
            foreach ($properties as $name => $value) {
                $candidates[$className . '::$' . $name] = $value;
                if (\is_object($value)) {
                    $queue[] = [$className . '::$' . $name, $value];
                }
            }

            foreach ($queue as [$prefix, $instance]) {
                $rc         = new \ReflectionClass($instance);
                $properties = $rc->getProperties(~\ReflectionProperty::IS_STATIC);
                foreach ($properties as $rp) {
                    $name              = $prefix . '->' . $rp->getName();
                    $candidates[$name] = $rp->getValue($instance);
                }
            }
        }

        return $candidates;
    }
}
