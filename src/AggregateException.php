<?php

namespace phasync;

/**
 * More than one thing failed in one `run()`: what `$fn` threw and the failures of coroutines nobody awaited, or several of those.
 *
 * `run()` throws this once every coroutine of its frame has ended, when there is more than one failure to report; with exactly one, it throws that exception itself. `getExceptions()` holds all of them: `$fn`'s first, then the coroutines' failures in the order they happened. None is dropped, and none is picked over the others: with several siblings failing, which one failed first is a matter of timing. The message lists each one with its class, message, file and line, so an uncaught one shows them all; `getPrevious()` is the first. With an exception handler on the frame ({@see Frame::setExceptionHandler()}), the handler gets the coroutines' failures one by one instead, and this is not thrown for them.
 *
 * ```php
 * try {
 *     phasync::run(function () {
 *         phasync::go(function () { phasync::sleep(0.01); throw new RuntimeException('cache down'); });
 *         phasync::go(function () { phasync::sleep(0.01); throw new LogicException('bad query'); });
 *     });
 * } catch (phasync\AggregateException $e) {
 *     if ($e->contains(LogicException::class)) {
 *         echo "a bug among ", count($e->getExceptions()), " failures\n";
 *     }
 * }
 * ```
 *
 * @see phasync::run
 * @see phasync\Frame::setExceptionHandler
 *
 * @topic coroutines
 *
 * @example examples/AggregateException/1-several-failures.php Several coroutines fail in one run
 */
final class AggregateException extends \RuntimeException
{
    /**
     * Wraps `$exceptions`, which `run()` does when it has more than one failure to report.
     *
     * @param non-empty-list<\Throwable> $exceptions `$fn`'s failure first, then the others in the order they happened
     */
    public function __construct(private readonly array $exceptions)
    {
        $lines = [];
        foreach ($exceptions as $e) {
            $lines[] = '  ' . \get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        }
        parent::__construct(\count($exceptions) . " failures:\n" . \implode("\n", $lines), 0, $exceptions[0]);
    }

    /**
     * Returns the failures, all of them or those of one class.
     *
     * ```php
     * foreach ($e->getExceptions(PDOException::class) as $failure) {
     *     $logger->critical($failure->getMessage());
     * }
     * ```
     *
     * @param class-string<\Throwable>|null $class only the failures that are instances of it (subclasses and implementations included)
     *
     * @return list<\Throwable> in order: `$fn`'s first, then the others as they happened
     *
     * @see AggregateException::contains
     */
    public function getExceptions(?string $class = null): array
    {
        if (null === $class) {
            return $this->exceptions;
        }

        return \array_values(\array_filter($this->exceptions, static fn (\Throwable $e) => $e instanceof $class));
    }

    /**
     * Whether one of the failures is an instance of `$class` (subclasses and implementations included).
     *
     * ```php
     * if ($e->contains(PDOException::class)) {
     *     $db->reconnect();
     * }
     * ```
     *
     * @param class-string<\Throwable> $class
     *
     * @see AggregateException::getExceptions
     */
    public function contains(string $class): bool
    {
        foreach ($this->exceptions as $e) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
