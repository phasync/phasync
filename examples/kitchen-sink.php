<?php
require(__DIR__ . '/../vendor/autoload.php');

// Just to trigger autoloading classes without interfering with the time measurement
phasync::run(function(){phasync::waitGroup();phasync::channel($a,$b);});

/**
 * Demo script to showcase much of the functionality of phasync.
 */
$t = \microtime(true);
try {
    phasync::run(function() {

        /**
         * A wait group simplifies waiting for multiple coroutines to complete
         */
        $wg = phasync::waitGroup();

        /**
         * A channel provides a way for a coroutine to pass execution over to
         * another coroutine, optionally containing a message. With buffering,
         * execution won't be handed over until the buffer is full.
         */
        phasync::channel($reader, $writer, 4); // Buffer up to 4 messages
        
        /**
         * `phasync::idle()` allows you to perform blocking tasks when the event
         * loop is about to wait for IO or timers. It is an opportunity to perform
         * blocking operations, such as calling the {@see \glob()} function or use
         * other potentially blocking functions with minimal disruption.
         */
        phasync::go(function() {
            echo elapsed() . "Idle work: Started idle work coroutine\n";
            for ($i = 0; $i < 10; $i++) {
                // Wait at most 0.1 seconds
                phasync::idle(0.1);
                $fib32 = fibonacci(32);
                echo elapsed() . "Idle work: Fib 32 = $fib32\n";
            }
        });

        /**
         * `WriteChannel::write()` Writing to a channel will either immediately buffer the message,
         * or the coroutine is suspended if no buffer space is available.
         * The reading coroutine (if any) is immediately resumed.
         */
        phasync::go(function() use ($wg, $writer) {
            echo elapsed() . "Channel writer: Started counter coroutine\n";
            $wg->add();
            for ($i = 0; $i < 10; $i++) {
                echo elapsed() . "Channel writer: Will write " . ($i + 1) . " to channel\n";
                $writer->write("Count: " . ($i + 1) . " / 10");
                echo elapsed() . "Channel writer: Wrote to channel\n";
                phasync::sleep(0.1);
            }
            echo elapsed() . "Channel writer: Wait group done\n";
            $writer->close();
            $wg->done();
        });

        /**
         * `phasync::sleep(float $time=0)` yields execution until the
         * next iteration of the event loop or until a number of seconds
         * have passed.
         */
        $future = phasync::go(function() use ($wg) {
            echo elapsed() . "Sleep: Started sleep coroutine\n";
            $wg->add();
            // Simulate some work
            phasync::sleep(1);
            echo elapsed() . "Sleep: Wait group done\n";
            $wg->done();
            echo elapsed() . "Sleep: Throwing exception\n";
            throw new Exception("This was thrown from the future");
        });

        /**
         * `phasync::preempt()` is a function that checks if the coroutine has
         * run for more than 20 ms (configurable) without suspending. If it
         * has, the coroutine is suspended until the next tick.
         */
        phasync::go(function() use ($wg) {
            echo elapsed() . "100 million: Started busy loop coroutine\n";
            $wg->add();
            for ($i = 0; $i < 100000000; $i++) {
                if ($i % 7738991 == 0) {
                    echo elapsed() . "100 million: Counter at $i, may preempt\n";
                    // The `phasync::preempt()` quickly checks if the coroutine
                    // has run for more than 20 ms, and if so pauses it to allow
                    // other coroutines to do some work.
                    phasync::preempt();
                }             
            }
            echo elapsed() . "100 million: Counter at $i. Wait group done\n";
            $wg->done();
        });

        /**
         * `phasync::run()` can be used to create a nested coroutine context.
         * Coroutines that are already running in the parent context will
         * continue to run, but this blocks the current coroutine until the
         * nested context is finished.
         * 
         * `ReadChannel::read()` will immediately return the next buffered
         * message available in the channel. If no message is available, the
         * coroutine will be paused and execution will immediately be passed
         * to the suspended writer (if any).
         */
        phasync::go(function() use ($reader) {
            // Not using wait group here, since this coroutine will finish naturally
            // from the channel closing.
            echo elapsed() . "Channel reader: Starting channel reader coroutine\n";
            phasync::run(function() {
                echo elapsed() . "Channel reader: Starting nested context\n";
                phasync::sleep(0.5);                
                echo elapsed() . "Channel reader: Nested context complete\n";
            });
            while ($message = $reader->read()) {
                echo elapsed() . "Channel reader: Received message '$message'\n";
            }
            echo elapsed() . "Channel reader: Received null, so channel closed\n";
        });

        /**
         * `WaitGroup::wait()` will wait until an equal number of `WaitGroup::add()`
         * and `WaitGroup::done()` calls have been performed. While waiting, all
         * other coroutines will be allowed to continue their work.
         */
        phasync::go(function() use ($wg) {
            echo elapsed() . "Wait group waiting: Started coroutine that waits for the wait group\n";
            $wg->wait();
            echo elapsed() . "Wait group waiting: Wait group finished, throwing exception\n";
            throw new Exception("Demo that this exception will be thrown from the top run() statement");
        });

        /**
         * `phasync::await(Fiber $future)` will block the current coroutine until
         * the other coroutine (created with {@see phasync::go()}) completes and
         * either throws an exception or returns a value.
         */
        phasync::go(function() use ($wg, $future) {
            echo elapsed() . "Future awaits: Started coroutine awaiting exception\n";
            try {
                phasync::await($future);
            } catch (Throwable $e) {
                echo elapsed() . "Future awaits: Caught '" . $e->getMessage() . "' exception\n";
            }
        });
                
        echo elapsed() . "Main run context: Waiting for wait group\n";
        /**
         * Calling `WaitGroup::wait()` in the main coroutine will still allow all
         * coroutines that have already started to continue their work. When all
         * added coroutines have called `WaitGroup::done()` the main coroutine will
         * resume.
         */
        $wg->wait();
        echo elapsed() . "Main run context: Wait group finished\n";

        try {
            echo elapsed() . "Main run context: Wait group finished, awaiting future\n";
            /**
             * If you await the result of a future multiple places, the same exception
             * will be thrown all those places.
             */
            $result = phasync::await($future);
        } catch (Exception $e) {
            echo elapsed() . "Main run context: Caught exception: " . $e->getMessage() . "\n";
        }
    });
} catch (Exception $e) {
    /**
     * Exceptions that are not handled within a `phasync::run()` context will be thrown
     * by the run context wherein they were thrown. This also applies to nested run 
     * contexts.
     */
    echo "Outside caught: ".$e->getMessage()."\n";
}

echo "Total time: " . \number_format(\microtime(true) - $t, 3) . " seconds\n";

function elapsed() {
    global $t;
    $elapsed = microtime(true) - $t;
    return number_format($elapsed, 4) . ' sec: ';
}

function fibonacci(int $n) {
    if ($n < 1) return 0;
    if ($n < 3) return 1;
    return fibonacci($n-1) + fibonacci($n-2);
}