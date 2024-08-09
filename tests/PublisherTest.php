<?php

use phasync\ChannelException;
use phasync\Util\WaitGroup;

phasync::setDefaultTimeout(3);

test('publisher subscribing deadlock protection', function () {
    expect(
        phasync::run(function () {
            phasync::publisher($subscribers, $publisher);

            return true;
        })
    )->toBeTrue();
    expect(function () {
        phasync::run(function () {
            phasync::publisher($subscribers, $publisher);

            foreach ($subscribers as $message) {
            }

            return true;
        });
    })->toThrow(ChannelException::class);
    expect(function () {
        phasync::run(function () {
            phasync::publisher($subscribers, $publisher);

            $publisher->write('something');
        });
    })->toThrow(ChannelException::class);
});
test('publisher semantics', function () {
    expect(phasync::run(function () {
        $counter = 0;
        phasync::publisher($sub, $pub);
        $wg = new WaitGroup();

        phasync::go(function () use ($sub, &$counter, $wg) {
            $wg->add();
            $expecting = 0;
            foreach ($sub as $message) {
                expect($message)->toBe($expecting++);
                ++$counter;
            }
            $wg->done();
        });
        phasync::go(function () use ($sub, &$counter, $wg) {
            $wg->add();
            $expecting = 0;
            foreach ($sub as $message) {
                expect($message)->toBe($expecting++);
                ++$counter;
                phasync::sleep(0.1);
            }
            $wg->done();
        });
        $lastSubscription = $sub->subscribe();
        phasync::go(function () use ($pub, $wg) {
            $wg->add();
            $pub->write(0);
            $pub->write(1);
            $pub->write(2);
            $pub->close();
            $wg->done();
        });
        $wg->await();

        phasync::go(function () use ($lastSubscription, &$counter) {
            // Even after closing a channel, a subscriber should still be able to get messages
            $expecting = 0;
            foreach ($lastSubscription as $message) {
                ++$counter;
                expect($message)->toBe($expecting++);
            }
        });

        return $counter;
    }))->toBe(9);
});
test('sending null via publisher', function () {
    phasync::run(function () {
        phasync::publisher($s, $p);
        $messages   = [];
        $subscriber = phasync::go(function () use ($s, &$messages) {
            $s = $s->subscribe();
            while (!$s->isClosed()) {
                $messages[] = $s->read();
            }
        });
        phasync::go(function () use ($p) {
            $p->write(null);
            $p->write('Great success');
            $p->write(null);
            $p->close();
        });
        phasync::await($subscriber);
        expect($messages)->toBe([null, 'Great success', null]);
    });
});
