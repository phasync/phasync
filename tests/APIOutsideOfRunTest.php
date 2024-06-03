<?php

use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertLessThan;
use function PHPUnit\Framework\assertTrue;

test('using phasync::go() outside of phasync::run()', function () {
    expect(function () {
        phasync::go(function () {});
    })->toThrow(LogicException::class);
});

test('using phasync::await() with a fiber not from phasync', function () {
    expect(function () {
        $fiber = new Fiber(function () { Fiber::suspend(); });
        $fiber->start();
        phasync::await($fiber);
    })->toThrow(LogicException::class);
});

test('using phasync::select() outside of phasync::run()', function () {
    expect(function () {
        phasync::select([]);
    })->toThrow(LogicException::class);
});

test('using phasync::cancel() with a fiber not from phasync', function () {
    expect(function () {
        $fiber = new Fiber(function () { Fiber::suspend(); });
        $fiber->start();
        phasync::cancel($fiber);
    })->toThrow(LogicException::class);
});

test('using phasync::preempt() outside of phasync is fast', function () {
    $t = \hrtime(true);
    phasync::preempt();
    assertLessThan(10000, \hrtime(true) - $t);
});

test('using phasync::sleep() outside of phasync works', function () {
    $t = \microtime(true);
    phasync::sleep();
    assertLessThan(1, \microtime(true) - $t);
    $t = \microtime(true);
    phasync::sleep(0.1);
    assertLessThan(0.11, \microtime(true) - $t);
    assertGreaterThan(0.09, \microtime(true) - $t);
});

test('using phasync::yield() outside of phasync is cheap', function () {
    $t = \hrtime(true);
    phasync::yield();
    assertLessThan(50000, \hrtime(true) - $t);
});

test('using phasync::idle() outside of phasync is cheap', function () {
    $t = \hrtime(true);
    phasync::idle();
    assertLessThan(10000, \hrtime(true) - $t);
});

test('using phasync::readable() outside of phasync is cheap', function () {
    $fp = \fopen(__FILE__, 'r');
    $t  = \microtime(true);
    phasync::readable($fp);
    assertLessThan(0.001, \microtime(true) - $t);
    $string = \fread($fp, 4096);
    \fclose($fp);
});

test('using phasync::writable() outside of phasync is cheap', function () {
    $tmpName = \tempnam(\sys_get_temp_dir(), 'phasync-test');
    $fp      = \fopen($tmpName, 'r+');
    $t       = \microtime(true);
    phasync::writable($fp);
    \fwrite($fp, 'hello');
    assertLessThan(0.001, \microtime(true) - $t);
    \fclose($fp);
    \unlink($tmpName);
});

test('using phasync::channel() outside of phasync fails', function () {
    expect(function () {
        phasync::channel($readChannel, $writeChannel);
    })->toThrow(LogicException::class);
});

test('using phasync::waitGroup() outside of phasync fails', function () {
    expect(function () {
        $wg = phasync::waitGroup();
        $wg->add();
        $wg->wait();
    })->toThrow(LogicException::class);
});

test('using phasync::raiseFlag() outside of phasync works', function () {
    /*
     * Libraries can raise flags even if they are not used together
     * with phasync. This is important, since there may be use cases
     * where phasync is waiting for some event in a library that is
     * not specifically requiring phasync.
     */
    phasync::raiseFlag(new stdClass());
    assertTrue(true);
});

test('using phasync::awaitFlag() outside of phasync fails', function () {
    expect(function () {
        phasync::awaitFlag(new stdClass());
    })->toThrow(LogicException::class);
});
