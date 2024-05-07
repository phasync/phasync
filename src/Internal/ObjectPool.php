<?php
namespace phasync\Internal;

use Closure;
use RuntimeException;

/**
 * A shared object pool implementation.
 * 
 * @package phasync
 */
final class ObjectPool {

    /**
     * Holds a pool of all instances in the pool
     * 
     * @var array<class-string, array<object>>
     */
    private static array $pools = [];

    /**
     * 
     * @var array<class-string, Closure>
     */
    private static array $resetFunctions = [];

    private function __construct() {}

    public static function addClass(string $className, Closure $resetFunction): void {
        self::$resetFunctions[$className] = $resetFunction;
    }

     /**
      * Get an instance from the object pool, if available
      *
      * @template T
      * @param class-string<T> $className 
      * @return T|null
      */
    public static function pop(string $className): ?object {
        if (!isset(self::$pools[$className]) || count(self::$pools[$className]) === 0) {
            return null;
        }
        return \array_pop(self::$pools[$className]);
    }

    /**
     * Add an instance to the object pool. Any other references
     * to the instances should be removed.
     * 
     * @param T $instance 
     * @return void 
     */
    public static function push(object $instance): void {
        $className = \get_class($instance);
        if (!isset(self::$resetFunctions[$className])) {
            throw new RuntimeException("Class not registered with the pool");
        }
        (self::$resetFunctions[$className])($instance);
        self::$pools[$className][] = $instance;
    }

}