<?php

use phasync\Util\WaitGroup;

phasync::setDefaultTimeout(3);

test('the coroutine that created a publisher can subscribe to it and receive what it publishes', function () {
    expect(
        phasync::run(function () {
            phasync::publisher($subscribers, $publisher);
            $subscription = $subscribers->subscribe();
            $publisher->write('mine');
            $publisher->close();
            $got = [];
            foreach ($subscription as $message) {
                $got[] = $message;
            }

            return $got;
        })
    )->toBe(['mine']);
});
test('writing to a publisher with no subscribers succeeds (the internal forwarding service is always a reader)', function () {
    // Not a deadlock case: Subscribers' internal service reads from the underlying channel
    // unconditionally from construction, regardless of whether any Subscriber exists yet,
    // so a write() always has somewhere to rendezvous with, and completes normally.
    $out = phasync::run(function () {
        phasync::publisher($subscribers, $publisher);
        $publisher->write('something');

        return 'written';
    });

    expect($out)->toBe('written');
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
        // A subscriber's isClosed() depends on a linked-list pointer that a separate internal
        // coroutine sets asynchronously as it drains the publisher, unlike a plain Channel's
        // isClosed() (a synchronous flag with no such gap). Right at the boundary that pointer
        // can still be unresolved, so isClosed() honestly answers "not yet known" (false) and
        // one more read() is needed to get a definitive answer -- which correctly returns null,
        // this time with eof true. Before the fix that made read() distinguish a written null
        // from end-of-stream (see the "not confused with the sentinel end-of-stream node" test),
        // this test expected only 3 messages; the exact old count depended on the same
        // now-fixed ambiguity and is not being relied on here.
        expect($messages)->toBe([null, 'Great success', null, null]);
    });
});
