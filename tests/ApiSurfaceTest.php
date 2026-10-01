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
        'static cancel(object $fiber, Stringable|string $message = "Operation cancelled", int $code = 0, ?Throwable $previous = null): void',
        'static channel(?phasync\\ReadChannelInterface &$read, ?phasync\\WriteChannelInterface &$write, int $bufferSize = 0): void',
        'static finally(Closure $fn): void',
        'static getContext(): object',
        'static getFiber(): Fiber',
        'static getLoop(): phasync\\EventLoop',
        'static getRootContext(): object',
        'static go(Closure $fn, array $args = [], ?object $context = null): Fiber',
        'static isRunning(): bool',
        'static publisher(?phasync\\SubscribersInterface &$subscribers, ?phasync\\WriteChannelInterface &$publisher): void',
        'static raiseFlag(object $signal): int',
        'static readable(mixed $resource, float $timeout = 1.7976931348623157e+308): mixed',
        'static run(Closure $fn, ?array $args = [], ?object $context = null): mixed',
        'static service(Closure $coroutine): void',
        'static sleep(float $seconds = 0): void',
        'static throw(Fiber $fiber, Throwable $exception): void',
        'static withContext(Closure $fn, object $context): mixed',
        'static writable(mixed $resource, float $timeout = 1.7976931348623157e+308): mixed',
        'static yield(): void',
    ]);
});
