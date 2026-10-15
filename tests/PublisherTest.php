<?php

use phasync\Util\WaitGroup;

/*
 * #113: Subscribers pinned the head of the ChannelMessage chain between subscribe() calls, so
 * every message published since the last subscribe() stayed referenced even once every
 * subscriber had read it -- unbounded growth, and a stack-overflowing recursive free (one nested
 * destructor per queued message) once the publisher was dropped. These run in a child process:
 * a regression here is exactly a crash, which must not take the whole suite down with it.
 */
if (!\function_exists('pubChild')) {
    /**
     * Runs $body as the main coroutine of phasync::run() in a child process and waits for it to
     * finish. Returns the exit code and everything printed (stdout and stderr together, so a
     * fatal error/segfault message is visible in assertion failures).
     *
     * @return array{exit: int, stdout: string}
     */
    function pubChild(string $body): array
    {
        $file = \tempnam(\sys_get_temp_dir(), 'phasync-pub-');
        \file_put_contents($file, "<?php\nrequire " . \var_export(\dirname(__DIR__) . '/vendor/autoload.php', true) . ";\nphasync::run(function () {\n$body\n});\n");
        $process = \proc_open(
            [\PHP_BINARY, '-d', 'display_errors=stderr', $file],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes
        );
        $stdout = \stream_get_contents($pipes[1]);
        $exit   = \proc_close($process);
        \unlink($file);

        return ['exit' => $exit, 'stdout' => $stdout];
    }
}

test('#113: memory stays flat reading 200k messages with nobody subscribing after, and freeing the publisher afterwards does not crash', function () {
    $r = pubChild(<<<'PHP'
        phasync::publisher($subscribers, $writer);
        $sub   = $subscribers->subscribe();
        $count = 0;
        $reader = phasync::go(function () use ($sub, &$count) {
            foreach ($sub as $message) {
                ++$count;
            }
        });
        $before = \memory_get_usage();
        for ($i = 0; $i < 200000; ++$i) {
            $writer->write($i);
            if (0 === $i % 1000) {
                phasync::sleep(0); // let the reader keep up
            }
        }
        $writer->close();
        phasync::await($reader);
        $grown = \memory_get_usage() - $before;
        echo "count=$count grown=$grown\n";
        unset($sub, $subscribers, $writer);
        echo "freed\n";
        PHP);

    expect($r['exit'])->toBe(0, $r['stdout']);
    expect($r['stdout'])->toContain('count=200000');
    expect($r['stdout'])->toContain('freed');
    \preg_match('/grown=(-?\d+)/', $r['stdout'], $matches);
    expect((int) $matches[1])->toBeLessThan(1 << 20); // bounded growth, not ~135 bytes * 200000
});

test('#113: dropping a subscriber that lagged 100,000 messages behind, inside a coroutine, does not crash', function () {
    $r = pubChild(<<<'PHP'
        phasync::publisher($subscribers, $writer);
        $doneWriting = new stdClass();
        $dropped = phasync::go(function () use ($subscribers, $doneWriting) {
            $lagging = $subscribers->subscribe(); // never read from
            phasync::awaitFlag($doneWriting);
            unset($lagging); // frees a chain of 100,000 unread ChannelMessage nodes
            echo "dropped\n";
        });
        for ($i = 0; $i < 100000; ++$i) {
            $writer->write($i);
            if (0 === $i % 1000) {
                phasync::sleep(0);
            }
        }
        $writer->close();
        phasync::raiseFlag($doneWriting);
        phasync::await($dropped);
        echo "done\n";
        PHP);

    expect($r['exit'])->toBe(0, $r['stdout']);
    expect($r['stdout'])->toContain('dropped');
    expect($r['stdout'])->toContain('done');
});

test('#113: dropping a lagging subscriber does not corrupt a second, still-reading subscriber sharing the same chain', function () {
    $out = phasync::run(function () {
        phasync::publisher($subscribers, $writer);
        $slow   = $subscribers->subscribe(); // never read from
        $fast   = $subscribers->subscribe();
        $got    = [];
        $reader = phasync::go(function () use ($fast, &$got) {
            foreach ($fast as $message) {
                $got[] = $message;
                if (50 === \count($got)) {
                    break; // let the writer get ahead before we finish reading
                }
            }
            foreach ($fast as $message) {
                $got[] = $message;
            }
        });
        for ($i = 0; $i < 300; ++$i) {
            $writer->write($i);
            if (0 === $i % 50) {
                phasync::sleep(0);
            }
        }
        $writer->close();
        // $slow has read nothing; $fast is stalled partway through the same chain. Dropping
        // $slow here must not sever the link $fast still needs to finish reading.
        unset($slow);
        phasync::await($reader);

        return $got;
    });

    expect($out)->toBe(\range(0, 299));
});

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
test('subscribing to a closed publisher gives a subscription at its end, instead of hanging', function () {
    $out = phasync::run(function () {
        phasync::publisher($subscribers, $publisher);
        $publisher->close();
        phasync::sleep(0.01); // the publisher's service reads the close
        $subscription = $subscribers->subscribe();
        $message      = $subscription->read(1, $eof);

        return [$message, $eof, $subscription->isClosed()];
    });

    expect($out)->toBe([null, true, true]);
});
