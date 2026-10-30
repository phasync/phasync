<?php

/*
 * A service is started by the event loop in its turn, not by the code that calls service(): a
 * coroutine belongs to the request (ext-phasync's sapi()) of the code that starts it, and a
 * service serves the process, whoever first needs it.
 */

test('service() returns before the service runs: the loop starts it in its turn', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        $service = phasync::service(function () use (&$log) {
            $log[] = 'service';
        });
        $log[] = 'caller';
        phasync::await($service);
    });
    expect($log)->toBe(['caller', 'service']);
});

test('a service cancelled before its first turn still starts, and gets the cancellation where it first waits', function () {
    $log = [];
    phasync::run(function () use (&$log) {
        phasync::service(function () use (&$log) {
            $log[] = 'started';
            try {
                phasync::sleep(5);
            } catch (phasync\CancelledException) {
                $log[] = 'cancelled';
            }
        });
    });
    expect($log)->toBe(['started', 'cancelled']);
});

test('a service first needed inside a sapi() request belongs to no request', function () {
    $sapi = new class {
        public function name(): string
        {
            return 'test-request';
        }

        public function write(string $data): bool
        {
            return true;
        }

        public function send_headers(int $status, ?string $statusLine, array $headers): void
        {
        }
    };
    [$inside, $service] = phasync::run(function () use ($sapi) {
        $service = null;
        $inside  = null;
        phasync\ext\sapi(function () use (&$service, &$inside) {
            $inside  = php_sapi_name();
            $service = phasync::service(static fn () => php_sapi_name());
        }, $sapi);

        return [$inside, phasync::await($service)];
    });
    expect($inside)->toBe('test-request')
        ->and($service)->toBe(PHP_SAPI);
})->skip(!function_exists('phasync\ext\sapi'), 'needs ext-phasync');
