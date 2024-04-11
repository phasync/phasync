<?php
namespace phasync;

use Closure;
use Fiber;
use FiberError;
use Throwable;
use WeakMap;

final class Loop {

    private static ?DriverInterface $driver = null;

    /**
     * Enqueues a Fiber to run on the next tick.
     * 
     * @internal
     * @param Fiber $fiber 
     * @return void 
     * @throws UsageError 
     */
    public static function enqueue(Fiber $fiber): void {
        if ($fiber->isRunning()) {
            throw new UsageError("Can't enqueue a running Fiber this way, use Loop::yield() instead");
        }
        self::getDriver()->enqueue($fiber);
    }

    /**
     * Run the main method until it returns a value.
     * 
     * @param Closure $main 
     * @param array $args 
     * @return mixed 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function run(Closure $main, mixed ...$args): mixed {
        $fiber = new Fiber($main);
        $fiber->start(...$args);
        $result = self::await($fiber);
        if (Fiber::getCurrent() === null) {
            while (self::getDriver()->count() > 0) {
                self::yield();
            }
        }
        return $result;
    }

    /**
     * Create a coroutine and run it. 
     * 
     * @param Closure $coroutine 
     * @param array $args 
     * @return Fiber 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function go(Closure $coroutine, mixed ...$args): Fiber {
        self::getFiber();
        $driver = self::getDriver();
        $fiber = new Fiber($coroutine);
        $fiber->start(...$args);
        // Enqueue the fiber unless it has terminated or scheduled itself
        if (!$fiber->isTerminated() && !$driver->isPending($fiber)) {
            $driver->enqueue($fiber);
        }
        return $fiber;
    }

    /**
     * Wait for the other fiber to complete, effectively by running the other
     * fiber inside the time slots of the current fiber.
     * 
     * @param Fiber $fiber 
     * @return mixed 
     * @throws Throwable
     */
    public static function await(Fiber $fiber): mixed {
        if ($fiber->isRunning()) {
            throw new UsageError("Can't await the currently running Fiber");
        }

        while (!$fiber->isTerminated()) {
            self::yield();
        }

        return $fiber->getReturn();
    }

    /**
     * The currently running Fiber yields its time slot, and will resume on the next tick.
     * 
     * @return void 
     * @throws Throwable
     */
    public static function yield(): void {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            // Exceptions thrown here will be thrown to the global scope
            self::getDriver()->tick();
        } else {
            self::getDriver()->enqueue($fiber);
            Fiber::suspend();
        }
    }

    /**
     * The currently running Fiber yields its time and will be resumed on the first tick
     * after the resource becomes readable or encounters an error.
     * 
     * @param mixed $resource 
     * @return void 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function readable(mixed $resource): void {
        $fiber = self::getFiber();
        self::getDriver()->readable($resource, $fiber);
        Fiber::suspend();
    }

    /**
     * The currently running Fiber yields its time and will be resumed on the first tick
     * after the resource becomes writable or encounters an error.
     * 
     * @param mixed $resource 
     * @return void 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function writable(mixed $resource): void {
        $fiber = self::getFiber();
        self::getDriver()->writable($resource, $fiber);
        Fiber::suspend();
    }

    /**
     * The currently running Fiber yields its time and will be resumed on the first tick
     * after the number of seconds has elapsed.
     * 
     * @param float $seconds 
     * @return void 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function sleep(float $seconds): void {
        $fiber = self::getFiber();
        self::getDriver()->delay($seconds, $fiber);
        Fiber::suspend();
    }

    public static function idle(): void {
        $fiber = self::getFiber();
        self::getDriver()->idle($fiber);
        Fiber::suspend();
    }

    private static function getDriver(): DriverInterface {
        if (self::$driver === null) {
            self::$driver = DriverFactory::createDriver();
        }
        return self::$driver;
    }

    private static function getFiber(): Fiber {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            throw new UsageError("Function can only be used within a coroutine");
        }
        return $fiber;
    }
}