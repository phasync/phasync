<?php

require '../vendor/autoload.php';

/*
 * Channel is an efficient method for coordinating coroutines.
 * The writer will pause after writing, allowing the reader to
 * read the message. When the reader becomes blocked again (for
 * example waiting for the next message, or because it tries to
 * read a file, the write resumes and can add a new message).
 *
 * The Channel class supports multiple readers and multiple writers,
 * but messages will only be read once by the first available reader.
 *
 * A channel can be buffered (via the buffer argument in the constructor),
 * which allows messages to be temporarily held allowing the writer to
 * resume working. This can be leveraged to design a queue system.
 */
use function phasync\await;
/*
 * Publisher is similar to Channel, but it is always buffered, and
 * any message will be delivered in order to all of the readers.
 *
 * This can be used to "multicast" the same data to many clients,
 * or as an event dispatcher. The readers will block whenever there
 * are no events pending. The read operation will return a null value
 * if the publisher goes away.
 */
use function phasync\defer;
/*
 * WaitGroup is a mechanism to simplify waiting for many simultaneous
 * processes to complete. It is analogous to Promise.all([]) known from
 * promise based designs. Each process will invoke the $waitGroup->add()
 * method, and finally they must invoke $waitGroup->done() when they are
 * finished.
 *
 * While all the simultaneous processes perform their task, you can call
 * $waitGroup->wait() to pause until the all coroutines have invoked
 * $waitGroup->done().
 *
 * WARNING! You must ensure that the $waitGroup->done() method is invoked,
 * or the $waitGroup->wait() method will block forever.
 */
use function phasync\file_get_contents;
/*
 * The library is primarily used via functions defined in the `phasync\`
 * namespace:
 */

use function phasync\file_put_contents;
/*
 * `run(Closure $coroutine, mixed ...$args): mixed`
 *
 * This function will launch the coroutine and wait for it
 * to either throw an exception or return with a value.
 * When this function is used from inside another run()
 * coroutine, it will block until all the coroutines that
 * were launched inside it are done.
 */

use function phasync\go;
/*
 * `go(Closure $coroutine, mixed ...$args): Fiber`
 *
 * This function will launch a coroutine and return a value
 * that may be resolved in the future. You can wait for a
 * fiber to finish by using {@see phasync\await()}, which
 * effectively is identical to using `run()`.
 */

use function phasync\idle;
/*
 * `await(Fiber $coroutine): mixed`
 *
 * This function will block the calling fiber until the
 * provided $coroutine either fails and throws an exception,
 * or returns a value.
 */

use phasync\Legacy\Channel\Channel;
/*
 * `defer(Closure $cleanupFunction): void`
 *
 * This is a method for scheduling cleanup or other tasks to
 * run after the coroutine completes. The deferred functions
 * will run in reverse order of when they were scheduled. The
 * functions will run immediately after the coroutine finishes,
 * unless an exception occurs and then they will be run when
 * the coroutine is garbage collected.
 */

use phasync\Publisher\Publisher;
/*
 * `sleep(float $seconds=0): void`
 *
 * This method will pause the coroutine for a number of seconds.
 * By invoking `sleep()` without arguments, your coroutine will
 * yield to allow other coroutines to work, but resume immediately.
 */

use function phasync\run;
/*
 * `wait_idle(): void`
 *
 * This function will pause the coroutine and allow it to resume only
 * when there is nothing else to do immediately.
 */

use function phasync\sleep;
/*
 * `file_get_contents(string $filename): string|false`
 *
 * This function will use non-blocking file operations to read the entire
 * file from disk. While the application is waiting for the disk to provide
 * data, other coroutines are allowed to continue working.
 */

use phasync\Util\WaitGroup;

/*
 * `file_put_contents(string $filename, mixed $data, int $flags = 0): void`
 *
 * This function is also non-blocking but has an API identical to the native
 * `file_put_contents()` function in PHP.
 */

/*
 * Other functions not documented here, but which are designed after the native
 * PHP standard library while being non-blocking. The functions *behave* as if
 * they are blocking, but will allow other coroutines to work in the time they
 * block.
 *
 * `stream_get_contents($stream, ?int $maxLength = null, int $offset = 0): string|false`
 * `fread($stream, int $length): string|false`
 * `fgets($stream, ?int $length = null): string|false`
 * `fgetc($stream): string|false`
 * `fgetcsv($stream, ?int $length = null, string $separator = ",", string $enclosure = "\"", string $escape = "\\"): array|false`
 * `fwrite($stream, string $data): int|false`
 * `ftruncate($stream, int $size): int|false`
 * `flock($stream, int $operation, int &$would_block = null): bool`
 */

// Launch your asynchronous application:
try {
    run(function () {
        $keep_running       = true;
        $maintenance_events = new Publisher();

        // launch a background task
        $count = go(function () use (&$keep_running, $maintenance_events) {
            $count = 0;

            while ($keep_running) {
                // do some maintenance work
                $data = file_get_contents(__FILE__); // this is asynchronous
                $maintenance_events->write(\md5($data) . " step $count");
                ++$count;
                // wait a while before repeating
                sleep(0.7); // allows other tasks to do some work
            }

            return $count;
        });

        $wait_group        = new WaitGroup();
        [$reader, $writer] = Channel::create(0);

        go(function () use ($reader) {
            echo "Waiting for completion messages\n";
            while ($message = $reader->read()) {
                echo 'Completed: ' . $message . "\n";
            }
            echo "No more completion messages\n";
        });

        $futureWithException = go(function () {
            throw new Exception('Just an exception');
        });

        // launch various workers
        for ($i = 0; $i < 3; ++$i) {
            // Create a subscription for the events
            $subscription = $maintenance_events->subscribe();
            go(function () use ($i, $subscription, $wait_group, $writer) {
                // Register with the $waitGroup
                $wait_group->add();
                defer(function () use ($wait_group) {
                    $wait_group->done();
                });

                echo "Worker $i waiting for events...\n";

                // This worker will handle at most 10 events
                for ($count = 0; $count < 4; ++$count) {
                    sleep(1 * $i);
                    $writer->write("Worker $i received: {$subscription->read()}");
                }

                /*
                 * If an exception is thrown here, it will appear to have been
                 * thrown from the outer coroutine while the $waitGroup->wait()
                 * function is blocking.
                 */

                echo "Worker $i done\n";
            });
        }

        echo "Waitgroup waiting\n";

        // wait for all workers to complete
        $wait_group->wait();
        echo "Waitgroup done\n";

        // stop the background maintenance
        $keep_running = false;

        echo 'A total of ' . await($count) . " maintenance steps were completed\n";

        echo "Trying to resolve the error value:\n";
        try {
            await($futureWithException);
        } catch (Throwable $e) {
            echo "Could not resolve the value: \n$e\n";
        }
    });
} catch (Throwable $e) {
    echo "I successfully caught the missed exception in Worker 1:\n";
    echo ' ' . $e->getMessage() . "\n";
}
