<?php

/**
 * Comprehensive tests for Subscriber class
 */
test('Subscriber activate does nothing (deprecated)', function () {
    phasync::run(function () {
        phasync::publisher($subscribers, $writer);
        $subscriber = $subscribers->subscribe();
        $subscriber->activate();
        expect($subscriber->isClosed())->toBeFalse();
        $writer->close();
    });
});

test('Subscriber await when active publisher available', function () {
    phasync::run(function () {
        phasync::publisher($subscribers, $writer);
        $subscriber = $subscribers->subscribe();
        phasync::go(function () use ($writer) {
            phasync::sleep(0.02);
            $writer->write('message');
        });
        // This should block until a message is available or timeout
        $start = \microtime(true);
        $subscriber->await(1.0);
        $elapsed = \microtime(true) - $start;
        // Should return after message is written (after 0.02s)
        expect($elapsed)->toBeGreaterThanOrEqual(0.01);
    });
});

test('Subscriber isReady returns true when closed', function () {
    phasync::run(function () {
        phasync::publisher($subscribers, $writer);
        $subscriber = $subscribers->subscribe();
        $writer->close();
        phasync::sleep(0.01);
        expect($subscriber->isReady())->toBeTrue();
    });
});

test('Subscriber isReady returns true when publisher is null', function () {
    phasync::run(function () {
        phasync::publisher($subscribers, $writer);
        $subscriber = $subscribers->subscribe();
        // Explicitly close to set publisher to null
        $subscriber->close();
        // isReady should return true when publisher is null
        expect($subscriber->isReady())->toBeTrue();
        // isClosed should also return true
        expect($subscriber->isClosed())->toBeTrue();
    });
});

test('Subscriber isReady returns false when no messages yet', function () {
    phasync::run(function () {
        phasync::publisher($subscribers, $writer);
        $subscriber = $subscribers->subscribe();
        // At subscription time, currentMessage.next is null (no messages published yet)
        expect($subscriber->isReady())->toBeFalse();
    });
});

test('Subscriber iterator stops when closed', function () {
    phasync::run(function () {
        phasync::publisher($subscribers, $writer);
        $subscriber = $subscribers->subscribe();
        $values     = [];
        phasync::go(function () use ($writer) {
            $writer->write('message');
            $writer->close();
        });
        foreach ($subscriber as $value) {
            $values[] = $value;
        }
        expect($values)->toBe(['message']);
    });
});

test('Subscriber isClosed when publisher is null', function () {
    phasync::run(function () {
        phasync::publisher($subscribers, $writer);
        $subscriber = $subscribers->subscribe();
        $subscriber->close();
        expect($subscriber->isClosed())->toBeTrue();
    });
});

test('Subscriber isReadable delegates to !isClosed', function () {
    phasync::run(function () {
        phasync::publisher($subscribers, $writer);
        $subscriber = $subscribers->subscribe();
        expect($subscriber->isReadable())->toBeTrue();
        $subscriber->close();
        expect($subscriber->isReadable())->toBeFalse();
    });
});

test('Subscriber read with eof flag when closed', function () {
    phasync::run(function () {
        phasync::publisher($subscribers, $writer);
        $subscriber = $subscribers->subscribe();
        $writer->close();
        phasync::sleep(0.01);
        $value = $subscriber->read(eof: $eof);
        expect($eof)->toBeTrue();
        expect($value)->toBeNull();
    });
});

test('Subscriber read returns messages in order', function () {
    phasync::run(function () {
        phasync::publisher($subscribers, $writer);
        $subscriber = $subscribers->subscribe();
        phasync::go(function () use ($writer) {
            $writer->write('msg1');
            $writer->write('msg2');
            $writer->write('msg3');
            $writer->close();
        });
        phasync::sleep(0.05);

        $msg1 = $subscriber->read(eof: $eof1);
        expect($eof1)->toBeFalse();
        expect($msg1)->toBe('msg1');

        $msg2 = $subscriber->read(eof: $eof2);
        expect($eof2)->toBeFalse();
        expect($msg2)->toBe('msg2');

        $msg3 = $subscriber->read(eof: $eof3);
        expect($eof3)->toBeFalse();
        expect($msg3)->toBe('msg3');

        $msgEnd = $subscriber->read(eof: $eofEnd);
        expect($eofEnd)->toBeTrue();
        expect($msgEnd)->toBeNull();
    });
});

test('Subscriber waits for message if needed', function () {
    phasync::run(function () {
        phasync::publisher($subscribers, $writer);
        $subscriber = $subscribers->subscribe();
        $received   = false;
        phasync::go(function () use ($subscriber, &$received) {
            phasync::sleep(0.02);
            $msg      = $subscriber->read();
            $received = 'delayed' === $msg;
        });
        phasync::sleep(0.01);
        $writer->write('delayed');
        phasync::sleep(0.02);
        expect($received)->toBeTrue();
    });
});

test('Subscriber close nulls publisher', function () {
    phasync::run(function () {
        phasync::publisher($subscribers, $writer);
        $subscriber = $subscribers->subscribe();
        expect($subscriber->isClosed())->toBeFalse();
        $subscriber->close();
        expect($subscriber->isClosed())->toBeTrue();
    });
});
