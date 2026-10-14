<?php

/*
 * The public surface of the phasync facade, locked for the 2.x line. A change here is a change
 * of the public API: make it on purpose, document it, and update this list.
 */

test('the phasync facade has exactly these public methods and signatures', function () {
    $signature = static function (ReflectionMethod $m): string {
        $parameters = [];
        foreach ($m->getParameters() as $p) {
            $default      = $p->isDefaultValueAvailable() ? ' = ' . \json_encode($p->getDefaultValue()) : '';
            $parameters[] = ($p->hasType() ? $p->getType() . ' ' : '') . ($p->isVariadic() ? '...' : '') . ($p->isPassedByReference() ? '&' : '') . '$' . $p->getName() . $default;
        }

        return ($m->isStatic() ? 'static ' : '') . $m->getName() . '(' . \implode(', ', $parameters) . ')' . ($m->hasReturnType() ? ': ' . $m->getReturnType() : '');
    };
    $actual = [];
    foreach ((new ReflectionClass('phasync'))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $actual[$method->getName()] = $signature($method);
    }
    \ksort($actual);

    expect(\array_values($actual))->toBe([
        'static await(object $fiberOrPromise, float $timeout = 1.7976931348623157e+308): mixed',
        'static awaitFlag(object $signal, float $timeout = 1.7976931348623157e+308): void',
        'static cancel(Fiber $fiber, Stringable|phasync\\CancelledException|string $message = "Operation cancelled", int $code = 0, ?Throwable $previous = null): void',
        'static channel(?phasync\\ReadChannelInterface &$read, ?phasync\\WriteChannelInterface &$write, int $bufferSize = 0): void',
        'static finally(Closure $fn): void',
        'static frame(): phasync\\Frame',
        'static getFiber(): Fiber',
        'static getLoop(): phasync\\EventLoop',
        'static go(callable $fn, mixed ...$args): Fiber',
        'static idle(float $after = 0): void',
        'static isRunning(): bool',
        'static onScopeChange(callable $fn): void',
        'static onSignal(int $signo, Closure $handler): void',
        'static publisher(?phasync\\SubscribersInterface &$subscribers, ?phasync\\WriteChannelInterface &$publisher): void',
        'static raiseFlag(object $signal): int',
        'static readable(mixed $resource, float $timeout = 1.7976931348623157e+308): mixed',
        'static run(callable $fn, mixed ...$args): mixed',
        'static service(callable $fn, mixed ...$args): Fiber',
        'static shielded(Closure $fn): mixed',
        'static shutdown(float $window = 1, ?phasync\\ShutdownException $exception = null): int',
        'static signal(array|int $signals, float $timeout = 1.7976931348623157e+308): int',
        'static sleep(float $seconds = 0): void',
        'static throw(Fiber $fiber, Throwable $exception): void',
        'static writable(mixed $resource, float $timeout = 1.7976931348623157e+308): mixed',
        'static yield(): void',
    ]);
});
