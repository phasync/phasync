The *phasync* framework is a fiber based coroutine library for PHP, that facilitates transparent context switching without using promises and generators. The stubs below documents the API.

```php
namespace phasync {
    final class phasync
    {
        public const READABLE = 1;
        public const WRITABLE = 2;
        public const EXCEPT   = 4;

        /**
         * Register a coroutine/Fiber to run in the event loop and await the result.
         * Running a coroutine this way also ensures that the event loop will run
         * until all nested coroutines have completed. If you want to create a coroutine
         * inside this context, and leave it running after - the coroutine must be
         * created from within another coroutine outside of the context, for example by
         * using a Channel.
         *
         * @throws FiberError
         * @throws Throwable
         */
        public static function run(Closure $fn, ?array $args=[], ?ContextInterface $context=null): mixed;

        /**
         * Creates a normal coroutine and starts running it. The coroutine will be associated
         * with the current context, and will block the current coroutine from completing
         * until it is done by returning or throwing.
         *
         * If parameter `$concurrent` is greater than 1, the returned coroutine will resolve
         * into an array of return values or exceptions from each instance of the coroutine.
         *
         * @throws LogicException
         * @throws Throwable
         */
        public static function go(Closure $fn, array $args=[], int $concurrent = 1, ?ContextInterface $context=null): Fiber;

        /**
         * Launches a service coroutine independently of the context scope.
         * This service will be permitted to continue but MUST stop running
         * when it is no longer providing services to other fibers. Failing
         * to do so will cause the topmost run() context to keep running.
         */
        public static function service(Closure $coroutine): void;

        /**
         * Wait for a coroutine or promise to complete and return the result.
         * If exceptions are thrown in the coroutine, they will be thrown here.
         *
         * @param float $timeout the number of seconds to wait at most
         *
         * @throws TimeoutException if the timeout is reached
         * @throws Throwable
         */
        public static function await(object $fiberOrPromise, ?float $timeout=null): mixed;

        /**
         * Block until one of the selectable objects or fibers terminate
         *
         * @param (SelectableInterface|Fiber)[] $selectables
         * @param resource[]                    $read        Wait for stream resources to become readable
         * @param resource[]                    $write       Wait for stream resources to become writable
         *
         * @throws LogicException
         * @throws FiberError
         * @throws Throwable
         *
         * @return SelectableInterface|resource|Fiber
         */
        public static function select(array $selectables, ?float $timeout=null, ?array $read=null, ?array $write=null): mixed;

        /**
         * Schedule a closure to run when the current coroutine completes. This function
         * is intended to be used when a coroutine uses a resource that must be cleaned
         * up when the coroutine finishes. Note that it may be more efficient to use a
         * try {} finally {} statement.
         */
        public static function finally(Closure $fn): void;

        /**
         * Cancel a suspended coroutine. This will throw an exception inside the
         * coroutine. If the coroutine handles the exception, it has the opportunity
         * to clean up any resources it is using. The coroutine MUST be suspended
         * using either {@see phasync::await()}, {@see phasync::sleep()}, {@see phasync::stream()}
         * or {@see phasync::awaitFlag()}.
         *
         * @throws RuntimeException if the fiber is not currently blocked
         */
        public static function cancel(Fiber $fiber, ?Throwable $exception=null): void;

        /**
         * Suspend the coroutine when it has been running for a configurable number of
         * microseconds. This function is designed to be invoked from within busy loops,
         * to allow other tasks to be performed. Use it at strategic places in library
         * functions that do not naturally suspend - and on strategic places in slow
         * calculations (avoiding invoking it on every iteration if possible).
         *
         * This function is highly optimized, but it benefits a lot from JIT because it
         * seems to be inlined.
         */
        public static function preempt(): void;

        /**
         * Yield time so that other coroutines can continue processing. Note that
         * if you intend to wait for something to happen in other coroutines, you
         * should use {@see phasync::yield()}, which will suspend the coroutine until
         * after any other fibers have done some work.
         *
         * @param float $seconds If null, the coroutine won't be resumed until another coroutine resumes
         *
         * @throws RuntimeException
         */
        public static function sleep(float $seconds=0): void;

        /**
         * Suspend the fiber until immediately after some other fibers has performed
         * work. Suspending a fiber this way will not cause a busy loop. If you intend
         * to perform work actively, you should use {@see phasync::sleep(0)}
         * instead.
         */
        public static function yield(): void;

        /**
         * Suspend the current fiber until the event loop becomes empty or will sleeps while
         * waiting for future events.
         */
        public static function idle(?float $timeout=null): void;

        /**
         * Make any stream resource context switch between coroutines when
         * they would block.
         *
         * @return false|resource
         */
        public static function io($resource);

        /**
         * Utility function to suspend the current fiber until a stream resource becomes readable,
         * by wrapping `phasync::stream($resource, $timeout, phasync::READABLE)`.
         *
         * @param resource $resource
         *
         * @throws FiberError
         * @throws Throwable
         *
         * @return resource Returns the same resource for convenience
         */
        public static function readable(mixed $resource, ?float $timeout=null): mixed;

        /**
         * Utility function to suspend the current fiber until a stream resource becomes readable,
         * by wrapping `phasync::stream($resource, $timeout, phasync::WRITABLE)`.
         *
         * @param resource $resource
         *
         * @throws FiberError
         * @throws Throwable
         *
         * @return resource Returns the same resource for convenience
         */
        public static function writable(mixed $resource, ?float $timeout=null): mixed;

        /**
         * Block the coroutine until the stream resource becomes readable, writable or raises
         * an exception or any combination of these.
         *
         * The bitmaps use self::READABLE, self::WRITABLE and self::EXCEPT.
         *
         * @param int $mode a bitmap indicating which events on the resource that should resume the coroutine
         *
         * @return int A bitmap indicating which events on the resource that was raised
         */
        public static function stream(mixed $resource, int $mode = self::READABLE | self::WRITABLE, ?float $timeout=null): int;

        /**
         * Creates a channel pair which can be used to communicate between multiple
         * coroutines. Channels should be used to pass serializable data, to support
         * passing channels to worker processes, but it is possible to pass more
         * complex data if you are certain the data will not be passed to other
         * processes.
         *
         * If a function is passed in either argument, it will be run a coroutine
         * with the ReadChannelInterface or the WriteChannelInterface as the first
         * argument.
         */
        public static function channel(?ReadChannelInterface &$read, ?WriteChannelInterface &$write, int $bufferSize=0): void;

        /**
         * A publisher works like channels, but supports many subscribing coroutines
         * concurrently.
         */
        public static function publisher(?SubscribersInterface &$subscribers, ?WriteChannelInterface &$publisher): void;

        /**
         * Signal all coroutines that are waiting for an event represented
         * by the object $signal to resume.
         *
         * @return int the number of resumed fibers
         */
        public static function raiseFlag(object $signal): int;

        /**
         * Pause execution of the current coroutine until an event is signalled
         * represented by the object $signal. If the timeout is reached, this function
         * throws TimeoutException.
         *
         * @throws TimeoutException if the timeout is reached
         * @throws Throwable
         */
        public static function awaitFlag(object $signal, ?float $timeout=null): void;

        /**
         * Get the currently running coroutine. If there is no currently
         * running coroutine, throws LogicException.
         *
         * @throws LogicException
         */
        public static function getFiber(): Fiber;

        /**
         * Get the context of the currently running coroutine. The there is no
         * currently running coroutine, throws LogicException.
         *
         * @throws LogicException
         */
        public static function getContext(): ContextInterface;

        /**
         * Register a callback to be invoked whenever an application enters the event
         * loop via the top level `phasync::run()` call.
         *
         * @see phasync::onExit()
         */
        public static function onEnter(Closure $enterCallback): void;

        /**
         * Register a callback to be invoked whenever an application exits the event
         * loop after a `phasync::run()` call.
         *
         * @see phasync::onEnter()
         */
        public static function onExit(Closure $exitCallback): void;

        /**
         * Set the interval between every time the {@see phasync::preempt()}
         * function will cause the coroutine to suspend running.
         */
        public static function setPreemptInterval(int $microseconds): void;

        /**
         * Configures handling of promises from other frameworks. The
         * `$promiseHandlerFunction` returns `false` if the value in
         * the first argument is not a promise. If it is a promise,
         * it attaches the `onFulfilled` and/or `onRejected` callbacks
         * from the second and third argument and returns true.
         *
         * @param Closure{mixed, Closure?, Closure?, bool} $promiseHandlerFunction
         */
        public static function setPromiseHandler(Closure $promiseHandlerFunction): void;

        /**
         * Returns the current promise handler function. This enables extending
         * the functionality of the existing promise handler without losing the
         * other integrations. {@see phasync::setPromiseHandler()} for documentation
         * on the function signature.
         */
        public static function getPromiseHandler(): Closure;

        /**
         * Set the driver implementation for the event loop. This must be
         * configured before this API is used and will throw a LogicException
         * if the driver has been implicitly set.
         *
         * @throws LogicException
         */
        public static function setDriver(DriverInterface $driver): void;

        /**
         * Set the default timeout for coroutine blocking operations. When
         * a coroutine blocking operation times out, a TimeoutException
         * is thrown.
         */
        public static function setDefaultTimeout(float $timeout): void;

        /**
         * Get the configured default timeout, which is used by all coroutine
         * blocking functions unless a custom timeout is specified.
         */
        public static function getDefaultTimeout(): float;
    }
    class io
    {
        /**
         * Asynchronously read file contents, similar to {@see file_get_contents()}.
         */
        public static function file_get_contents(string $filename): string|false;

        /**
         * Writes data to a file asynchronously.
         */
        public static function file_put_contents(string $filename, mixed $data, int $flags = 0): int|false;

        /**
         * Asynchronously reads until EOF from a given stream resource.
         */
        public static function stream_get_contents($stream, ?int $maxLength = null, int $offset = -1): string|false;

        /**
         * Non-blocking binary-safe file read.
         */
        public static function fread($stream, int $length): string|false;

        /**
         * Non-blocking get line from file pointer
         *
         * @param resource $stream the stream resource to read from
         * @param int      $length maximum number of bytes to read
         *
         * @return string|false the read data on success, or false on failure
         */
        public static function fgets($stream, ?int $length = null): string|false;

        /**
         * Non-blocking get character from file pointer.
         *
         * @param resource $stream the stream resource to read from
         *
         * @return string|false the read data on success, or false on failure
         */
        public static function fgetc($stream): string|false;

        /**
         * Non-blocking get line from file pointer and parse for CSV fields
         *
         * @param resource $stream the stream resource to read from
         * @param int      $length maximum number of bytes to read
         *
         * @throws \Exception if the read operation fails
         *
         * @return string|false the read data on success, or false on failure
         */
        public static function fgetcsv($stream, ?int $length = null, string $separator = ',', string $enclosure = '"', string $escape = '\\'): array|false;

        /**
         * Format line as CSV and write to file pointer
         *
         * @param resource $stream
         *
         * @throws \TypeError
         */
        public static function fputcsv($stream, array $fields, string $separator = ',', string $enclosure = '"', string $escape = '\\', string $eol = "\n"): int|false;

        /**
         * Async version of {@see \fwrite()}
         *
         * @param resource $stream the stream resource to write to
         * @param string   $data   the data to write
         *
         * @throws \Exception if the write operation fails
         *
         * @return int|false the number of bytes written, or false on failure
         */
        public static function fwrite($stream, string $data): int|false;

        /**
         * Async version of {@see \ftruncate()}
         *
         * @param resource $stream the stream resource to write to
         * @param int      $size   the size to truncate to
         *
         * @return int|false returns true on success or false on failure
         */
        public static function ftruncate($stream, int $size): int|false;

        /**
         * Async version of {@see \flock()}
         *
         * @throws \Exception
         * @throws \Throwable
         */
        public static function flock($stream, int $operation, ?int &$would_block = null): bool;
    }
    interface WriteChannelInterface extends SelectableInterface
    {
        /**
         * This function can be used to activate the channel so that
         * the deadlock protection does not fail.
         */
        public function activate(): void;

        /**
         * Closes the channel.
         */
        public function close(): void;

        /**
         * True if the channel is no longer writable.
         */
        public function isClosed(): bool;

        /**
         * Write a chunk of data to the writable stream. Writing may
         * cause the coroutine to be suspended for example in the case
         * of blocking IO.
         *
         * @param string $value
         *
         * @return int
         */
        public function write(\Serializable|array|string|float|int|bool|null $value): void;

        /**
         * Returns true if the channel is still readable.
         */
        public function isWritable(): bool;
    }
    /**
     * A readable channel provides messages asynchronously from various sources.
     * Messages must be serializable. Reading when no message will return `null`
     * when the channel is closed, and will block the coroutine if no messages
     * are buffered. The coroutine will be resumed as soon as another coroutine
     * writes to the channel.
     */
    interface ReadChannelInterface extends SelectableInterface, Traversable
    {
        /**
         * This function can be used to activate the channel so that
         * the deadlock protection does not fail.
         */
        public function activate(): void;

        /**
         * Closes the channel.
         */
        public function close(): void;

        /**
         * True if the channel is no longer readable.
         */
        public function isClosed(): bool;

        /**
         * Returns the next item that can be read. If no item is
         * available and the channel is still open, the function
         * will suspend the coroutine and allow other coroutines
         * to work. Returns null if the channel is closed, but null
         * can also be sent over the channel.
         *
         * @throws \RuntimeException
         */
        public function read(): \Serializable|array|string|float|int|bool|null;

        /**
         * Returns true if the channel is still readable.
         */
        public function isReadable(): bool;
    }
    interface SubscribersInterface extends IteratorAggregate
    {
        /**
         * Subscribe to future messages from the publisher channel.
         */
        public function subscribe(): SubscriberInterface;
    }
    /**
     * Selectable objects can be used together with {@see phasync::select()} to wait for
     * multiple events simultaneously.
     */
    interface SelectableInterface
    {
        /**
         * Wait for the resource to be non-blocking.
         */
        public function await(): void;
    }
}
namespace phasync\Context {
    interface ContextInterface extends \ArrayAccess
    {
        /**
         * Invoked the first time a context is attached to a coroutine.
         * The function MUST throw {@see ContextUsedException} if it is
         * was previously activated.
         */
        public function activate(): void;

        /**
         * Returns true if the context has been activated.
         */
        public function isActivated(): bool;

        /**
         * If an exception was thrown in the context, and not handled
         * it should be assigned here. This will ensure the exception
         * is thrown by `phasync::run()`.
         *
         * @throws \LogicException if the exception is already set
         */
        public function setContextException(\Throwable $exception): void;

        /**
         * Returns the exception for the context, if it has been set.
         */
        public function getContextException(): ?\Throwable;

        /**
         * All the Fiber instances attached to this context and their
         * start time.
         *
         * @return \WeakMap<\Fiber, float>
         */
        public function getFibers(): \WeakMap;
    }
}
namespace phasync\Util {

    use phasync\SelectableInterface;

    final class RateLimiter implements SelectableInterface {
        public function __construct(float $eventsPerSecond, int $burst=0);
        /**
         * Blocks the current coroutine if rate limiting is needed.
         *
         * @throws \RuntimeException
         */
        public function await(): void;
    }
    final class WaitGroup implements SelectableInterface {
        /**
         * Add work to the WaitGroup.
         */
        public function add(): void;
        /**
         * Signal that work has been completed to the WaitGroup.
         *
         * @throws \LogicException
         */
        public function done(): void;
        /**
         * Wait until the WaitGroup has signalled that all work
         * is done.
         *
         * @throws \Throwable
         */
        public function await(): void;
    }
}
namespace phasync\Services {
    final class CurlMulti
    {
        /**
         * Run the CurlHandle asynchronously (effectively works like \curl_exec())
         */
        public static function await(\CurlHandle $ch);
    }
}
```
