<?php

use phasync\Util\RateLimiter;

phasync::setDefaultTimeout(10);

test('test basic rate limiter functionality', function () {
    expect(function () {
        phasync::run(function () {
            $rateLimiter = new RateLimiter(10); // 10 events per second
            $startTime   = \microtime(true);
            for ($i = 0; $i < 10; ++$i) {
                $rateLimiter->wait();
            }
            $endTime = \microtime(true);

            $elapsedTime = $endTime - $startTime;
            expect($elapsedTime)->toBeGreaterThanOrEqual(1.0); // Should take at least 1 second
        });
    })->not->toThrow(Throwable::class);
});

test('test rate limiter with high rate', function () {
    expect(function () {
        phasync::run(function () {
            $rateLimiter = new RateLimiter(100); // 100 events per second
            $startTime   = \microtime(true);
            for ($i = 0; $i < 100; ++$i) {
                $rateLimiter->wait();
            }
            $endTime = \microtime(true);

            $elapsedTime = $endTime - $startTime;
            expect($elapsedTime)->toBeGreaterThanOrEqual(1.0); // Should take at least 1 second
        });
    })->not->toThrow(Throwable::class);
});

test('test rate limiter with invalid rate', function () {
    expect(function () {
        new RateLimiter(-1); // Invalid rate
    })->toThrow(InvalidArgumentException::class);
});

test('test rate limiter burst functionality', function () {
    expect(function () {
        phasync::run(function () {
            $rateLimiter = new RateLimiter(10, 5); // 10 events per second, burst of 5
            $startTime   = \microtime(true);
            for ($i = 0; $i < 5; ++$i) { // Burst should allow the first 5 events immediately
                $rateLimiter->wait();
            }
            $burstTime = \microtime(true);
            for ($i = 0; $i < 5; ++$i) { // Remaining 5 events should respect the rate limit
                $rateLimiter->wait();
            }
            $endTime = \microtime(true);

            $burstElapsedTime = $burstTime - $startTime;
            $totalElapsedTime = $endTime - $startTime;

            expect($burstElapsedTime)->toBeLessThan(0.1); // Burst should be almost immediate
            expect($totalElapsedTime)->toBeGreaterThanOrEqual(1.0); // Total should take at least 1 second
        });
    })->not->toThrow(Throwable::class);
});

test('test rate limiter with zero burst', function () {
    expect(function () {
        phasync::run(function () {
            $rateLimiter = new RateLimiter(10, 0); // 10 events per second, no burst
            $startTime   = \microtime(true);
            for ($i = 0; $i < 10; ++$i) {
                $rateLimiter->wait();
            }
            $endTime = \microtime(true);

            $elapsedTime = $endTime - $startTime;
            expect($elapsedTime)->toBeGreaterThanOrEqual(1.0); // Should take at least 1 second
        });
    })->not->toThrow(Throwable::class);
});
