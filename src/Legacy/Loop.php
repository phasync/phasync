<?php
namespace phasync\Legacy;

use Closure;
use CurlHandle;
use CurlMultiHandle;
use Exception;
use Fiber;
use FiberError;
use LogicException;
use mysqli;
use phasync\Drivers\DriverInterface;
use phasync\Debug;
use Throwable;
use WeakMap;

final class Loop {

    private static ?DriverInterface $driver = null;

    /**
     * Traces which fibers are awaiting the result of other fibers
     * to detect circular dependencies.
     * 
     * @var null|WeakMap<Fiber, Fiber>
     */
    private static ?WeakMap $dependencyGraph = null;

    private static ?CurlMultiHandle $curl = null;
    private static array $curlHandles = [];

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
        if ($fiber->isTerminated()) {
            throw new UsageError("Can't enqueue a terminated Fiber.");
        }
        self::getDriver()->enqueue($fiber);
    }

    /**
     * Suspend the current coroutine until a flag is raised.
     * The `$object` represents the flag.
     * 
     * @param object $flag 
     * @param float $timeout A timeout (defaults to 30 seconds) for the operation to complete. 0 disables.
     * @return void 
     * @throws UsageError 
     * @throws LogicException 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function awaitFlag(object $flag, float $timeout=null): void {
        $fiber = self::getFiber();
        self::getDriver()->waitForFlag($flag, $timeout, $fiber);
        Fiber::suspend();
    }

    /**
     * Suspend the current coroutine until there is activity on the curl handle.
     * 
     * @param CurlHandle $curl 
     * @param float|null $timeout 
     * @return void 
     * @throws UsageError 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function awaitCurl(CurlHandle $curl, float $timeout=null): void {
        $fiber = self::getFiber();
        self::getDriver()->waitForCurl($curl, $timeout, $fiber);
        Fiber::suspend();
    }

    /**
     * Suspend the current coroutine until there is activity on the mysqli handle.
     * 
     * @param mysqli $connection 
     * @param float|null $timeout 
     * @return void 
     * @throws UsageError 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function awaitMysql(mysqli $connection, float $timeout=null): void {
        $fiber = self::getFiber();
        self::getDriver()->waitForMysqli($connection, $timeout, $fiber);
        Fiber::suspend();
    }

    /**
     * Trigger a flag which causes other fibers to resume operation.
     * 
     * @param object $signal 
     * @return void 
     * @throws UsageError 
     */
    public static function raiseFlag(object $signal): void {
        self::getDriver()->raiseFlag($signal);
    }

    /**
     * Register a function to run when the current coroutine completes.
     * 
     * @param Closure $deferred 
     * @return void 
     */
    public static function defer(Closure $deferred): void {
        self::getDriver()->defer($deferred, self::getFiber());
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
    public static function run(Closure $main, ?array $args=[], ?ContextInterface $context=null): mixed {

        if ($context === null) {
            $context = new DefaultContext();
        }

        $driver = self::getDriver();
        $currentFiber = Fiber::getCurrent();
        if ($currentFiber !== null) {
            // This is a nested run, so we must simulate it blocking only
            // until the fibers started inside it has completed.
            $fiber = self::startFiber($main, $args, $context);
            $result = self::await($fiber);
            while ($context->getFibers()->count() > 1) {
                self::yield();
            }
            $fiber = null;
            while ($context->getFibers()->count() > 0) {
                self::yield();
            }
            return $result;
        } else {
            $fiber = self::startFiber($main, $args, $context);
            while (!$fiber->isTerminated()) {
                $driver->tick();
            }
            if ($exception = $driver->getException($fiber)) {
                throw $exception;
            }
            $result = $fiber->getReturn();
            $fiber = null;
            while ($driver->count() > 0) {
                $driver->tick();
            }
            return $result;
        }
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
        if (!Fiber::getCurrent()) {
            throw new UsageError("go() can only be used inside a run() context");
        }
        $driver = self::getDriver();
        $fiber = self::startFiber($coroutine, $args);

        // When the number of coroutines is significant, allow other coroutines to
        // work when launching new coroutines
        if (\mt_rand(0,100)==0 && $driver->count() > 1000) {
            self::yield();
        }

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

        if (!$fiber->isTerminated()) {
            if (!self::getDriver()->isPending($fiber)) {
                throw new LogicException("The fiber you're awaiting is not managed by phasync");
            }
    
            if (self::$dependencyGraph === null) {
                self::$dependencyGraph = new WeakMap();
            }
    
            $current = Fiber::getCurrent();
    
            if ($current === null) {
                // This scenario is actually not supposed to happen
                // since the run() function should wait for all managed
                // fibers to complete.
                while (!$fiber->isTerminated()) {
                    self::getDriver()->tick();
                }
            } else {
                // Detect cyclic awaits
                $next = $fiber;
                while (isset(self::$dependencyGraph[$next])) {
                    if (self::$dependencyGraph[$next] === $current) {
                        throw new LogicException("A dependency cycle has been detected");
                    }
                    $next = self::$dependencyGraph[$next];
                }
                self::$dependencyGraph[$current] = $fiber;
    
                while (!$fiber->isTerminated()) {
                    self::yield();
                }
    
                unset(self::$dependencyGraph[$current]);
            }    
        }

        try {
            return $fiber->getReturn();
        } catch (FiberError $fe) {
            // FiberError is thrown in getReturn() if the fiber actually
            // threw an exception. Handling it in a catch block is more
            // efficient than checking every fiber for throwing before 
            // calling getReturn().
            $e = self::getDriver()->getException($fiber);
            if ($e !== null) {
                throw $e;
            }
            throw $fe;
        }
    }

    /**
     * The currently running Fiber yields its time slot, and will resume on the next tick.
     * 
     * @return void 
     * @throws Throwable
     */
    public static function yield(): void {
        self::getDriver()->enqueue(self::getFiber());
        Fiber::suspend();
    }

    /**
     * The currently running Fiber yields its time and will be resumed on the first tick
     * after the resource becomes readable or encounters an error.
     * 
     * @param mixed $resource 
     * @param float $timeout A timeout (defaults to 30 seconds) for the operation to complete. 0 disables.
     * @return void 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function readable(mixed $resource, float $timeout=null): void {
        $fiber = self::getFiber();
        self::getDriver()->readable($resource, $timeout, $fiber);
        Fiber::suspend();
    }

    /**
     * The currently running Fiber yields its time and will be resumed on the first tick
     * after the resource becomes writable or encounters an error.
     * 
     * @param mixed $resource 
     * @param float $timeout A timeout (defaults to 30 seconds) for the operation to complete. 0 disables.
     * @return void 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function writable(mixed $resource, float $timeout=null): void {
        $fiber = self::getFiber();
        self::getDriver()->writable($resource, $timeout, $fiber);
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

    /**
     * Pause the fiber until the event loop becomes idle.
     * 
     * @param float $timeout A timeout (defaults to 30 seconds) for the operation to complete. 0 disables.
     * @return void 
     * @throws UsageError 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function idle(float $timeout=null): void {
        $fiber = self::getFiber();
        self::getDriver()->idle($timeout, $fiber);
        Fiber::suspend();
    }

    public static function context(): ContextInterface {
        return self::getDriver()->getContext(self::getFiber());
    }

    /**
     * Will be invoked for any unhandled exceptions.
     * 
     * @param Throwable $exception 
     * @return void 
     */
    public static function handleException(Throwable $exception): void {
        if (!Fiber::getCurrent()) {
            throw $exception;
        }
        \error_log((string) $exception, $exception->getCode());
    }

    /**
     * Get the driver instance.
     * 
     * @internal
     * @return DriverInterface 
     */
    public static function getDriver(): DriverInterface {
        if (self::$driver === null) {
            self::$driver = DriverFactory::createDriver();
        }
        return self::$driver;
    }    

    /**
     * Get the current fiber and throw exception if there is no current fiber.
     * 
     * @return Fiber 
     * @throws UsageError 
     */
    private static function getFiber(): Fiber {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            throw new UsageError("Function can only be used within a coroutine");
        }
        return $fiber;
    }

    /**
     * Start the function within a Fiber and register it for management.
     * 
     * @param Closure $function 
     * @param array $args 
     * @return Fiber 
     * @throws FiberError 
     * @throws Throwable 
     */
    private static function startFiber(Closure $function, array $args, ?ContextInterface $context = null): Fiber {
        return self::getDriver()->startFiber($function, $args, $context);
    }
}