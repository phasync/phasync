# phasync: High-concurrency PHP

*phasync* brings Go-inspired concurrency to PHP, utilizing native and ultra-fast coroutines to manage thousands of simultaneous operations efficiently. By leveraging modern PHP features like fibers, *phasync* simplifies asynchronous programming, allowing for clean, maintainable code that performs multiple tasks simultaneously with minimal overhead.

Transform how your applications handle IO-bound and CPU-intensive operations with non-blocking execution patterns. Whether building high-traffic web apps, data processing systems, or real-time APIs, *phasync* equips you with the tools to scale operations smoothly and reliably.


## ChatGPT assistance!

By pasting the `CHATBOT.txt` file into ChatGPT or your preferred chatbot, you can get assistance and ask questions about how to integrate `phasync` with your projects.


## Integrate your libraries

Existing libraries can easily be made non-blocking with `phasync`. The essence is to use the functions
`phasync\Loop::readable($stream)` when you need to wait for a stream resource to become readable, or
`phasync\Loop::writable($stream)` when you need to wait for a stream resource to become writable. Also,
`phasync\fread()` and `phasync\fwrite()` can be used as alternatives.


## Work in progress

While the library seems to be stable, it has not been battle tested. We want enthusiasts to contribute in building tests, documentation and discussion around the architecture.

Please contribute; we want asynchronous tools to work with:

  * A `Process` class, for running background processes using `proc_open()`.

    With a `Process` class, we could use a child `php` process to run functions that can't be made non-blocking, such as directory scans, dns lookups and so on. It can also be used to scale the application to utilize multiple CPU cores. Another use for such a class, is to run the `sqlite3` command as a separate process, allowing asynchronous queries to an sqlite3 database.

  * An asynchronous `MySQL` driver built on top of `mysqli` which supports everything needed for asynchronous
    database access.

  * A `TcpServer` class, for developing fast and concurrent TCP servers using `stream_socket_server`.
 
    A `TcpServer` class should make developing TCP servers a breeze. See `phasync\TcpServer` for the work in progress. Combined with Channels, WaitGroups and Publishers, a lot of powerful services can be designed.

    This class will be the foundation for various methods for serving phasync applications as standalone and
    concurrent applications; `HttpServer` or `FastCGIServer`.

  * A `TcpClient` class which would simplify developing clients for important systems like `redis` or 
    `memcached` that are also asynchronous.

  * A `HttpClient` using `TcpClient` for asynchronous and concurrent requests using `curl_multi_init`.

  * A `http://` and `https://` and `file://` stream wrapper for making these asynchronous.


## Combine with your legacy code

*phasync* is meticulously crafted to enhance your existing applications with powerful concurrency capabilities without requiring major architectural changes. The run() function initiates a coroutine and an event loop seamlessly within your current codebase, enabling you to add high-performance asynchronous operations.


### Example: Asynchronous File Processing in a Web Controller

```php
<?php
require('../vendor/autoload.php');
use phasync\{run, go, file_get_contents};

class MyController {

    #[Route("/", "index")]
    public function index() {
        // Initiate the event loop within your existing controller method
        run(function() {
            // Process each text file asynchronously
            foreach (glob('/some/path/*.txt') as $file) {
                go(function() use ($file) {
                    $data = file_get_contents($file);  // Non-blocking file read
                    do_something($data);  // Replace with your processing logic
                });
            }
        });
        // The run function will wait here until all file operations are complete
    }
}
```

### Benefits

 > *Non-intrusive*: Integrate asynchronous features without disrupting the structure of your existing PHP projects.
 > *Enhanced Performance*: Utilize non-blocking IO to handle file operations, database queries, and network calls more efficiently.
 > *Easy Adoption*: With minimal changes to how functions are called, you can transform synchronous tasks into asynchronous ones.

This approach not only preserves your application's existing architecture but also enhances responsiveness and scalability by offloading heavy IO operations to *phasync*'s non-blocking routines. Ideal for applications requiring improvements in handling large volumes of data or high levels of user interaction without a complete rewrite.


## Testers, Documentors and Contributors Wanted!

While *phasync* is faster and simpler, especially with rational and understandable exception handling compared to Promise-based implementations like reactphp or amphp, it is still evolving. We invite testers and contributors to help expand its capabilities and ecosystem.


## Example

This comprehensive example documents many of the features of *phasync*. The script is
available in the `examples/` folder of this project.

```php
<?php
require('../vendor/autoload.php');

/**
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
use phasync\Channel;

/**
 * Publisher is similar to Channel, but it is always buffered, and 
 * any message will be delivered in order to all of the readers.
 * 
 * This can be used to "multicast" the same data to many clients,
 * or as an event dispatcher. The readers will block whenever there
 * are no events pending. The read operation will return a null value
 * if the publisher goes away.
 */
use phasync\Publisher;

/**
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
use phasync\WaitGroup;

/**
 * The library is primarily used via functions defined in the `phasync\`
 * namespace:
 */

use function phasync\run;
/** 
 * `run(Closure $coroutine, mixed ...$args): mixed`
 * 
 * This function will launch the coroutine and wait for it
 * to either throw an exception or return with a value.
 * When this function is used from inside another run() 
 * coroutine, it will block until all the coroutines that
 * were launched inside it are done.
 */

use function phasync\go;
/**
 * `go(Closure $coroutine, mixed ...$args): Fiber`
 * 
 * This function will launch a coroutine and return a value
 * that may be resolved in the future. You can wait for a
 * fiber to finish by using {@see phasync\await()}, which
 * effectively is identical to using `run()`.
 */

use function phasync\await;
/**
 * `await(Fiber $coroutine): mixed`
 * 
 * This function will block the calling fiber until the
 * provided $coroutine either fails and throws an exception,
 * or returns a value.
 */

use function phasync\defer;
/**
 * `defer(Closure $cleanupFunction): void`
 * 
 * This is a method for scheduling cleanup or other tasks to
 * run after the coroutine completes. The deferred functions
 * will run in reverse order of when they were scheduled. The
 * functions will run immediately after the coroutine finishes,
 * unless an exception occurs and then they will be run when
 * the coroutine is garbage collected.
 */

use function phasync\sleep;
/**
 * `sleep(float $seconds=0): void`
 * 
 * This method will pause the coroutine for a number of seconds.
 * By invoking `sleep()` without arguments, your coroutine will
 * yield to allow other coroutines to work, but resume immediately.
 */

use function phasync\wait_idle;
/**
 * `wait_idle(): void`
 * 
 * This function will pause the coroutine and allow it to resume only
 * when there is nothing else to do immediately.
 */

use function phasync\file_get_contents;
/**
 * `file_get_contents(string $filename): string|false`
 * 
 * This function will use non-blocking file operations to read the entire
 * file from disk. While the application is waiting for the disk to provide
 * data, other coroutines are allowed to continue working.
 */

 use function phasync\file_put_contents;
 /**
  * `file_put_contents(string $filename, mixed $data, int $flags = 0): void`
  * 
  * This function is also non-blocking but has an API identical to the native
  * `file_put_contents()` function in PHP.
  */

/**
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
    run(function() {

        $keep_running = true;
        $maintenance_events = new Publisher();
    
        // launch a background task
        $count = go(function() use (&$keep_running, $maintenance_events) {
            $count = 0;
    
            while ($keep_running) {
                // do some maintenance work
                $data = file_get_contents(__FILE__); // this is asynchronous
                $maintenance_events->write(md5($data) . " step $count");
                $count++;
                // wait a while before repeating
                sleep(0.7); // allows other tasks to do some work
            }
    
            return $count;
        });
    
        $wait_group = new WaitGroup();
        [$reader, $writer] = Channel::create(0);
    
        go(function() use ($reader) {
            echo "Waiting for completion messages\n";
            while ($message = $reader->read()) {
                echo "Completed: " . $message . "\n";
            }
            echo "No more completion messages\n";
        });
    
        $futureWithException = go(function() {
            throw new Exception("Just an exception");
        });
    
        // launch various workers
        for ($i = 0; $i < 3; $i++) {
            // Create a subscription for the events
            $subscription = $maintenance_events->subscribe();
            go(function() use ($i, $subscription, $wait_group, $writer) {
                // Register with the $waitGroup
                $wait_group->add();
                defer(function() use ($wait_group) {
                    $wait_group->done();
                });
    
                echo "Worker $i waiting for events...\n";
    
                // This worker will handle at most 10 events
                for ($count = 0; $count < 4; $count++) {
                    sleep(1 * $i);
                    $writer->write("Worker $i received: {$subscription->read()}");
                }

                /**
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
    
        echo "A total of " . await($count) . " maintenance steps were completed\n";
    
        echo "Trying to resolve the error value:\n";
        try {
            await($futureWithException);
        } catch (Throwable $e) {
            echo "Could not resolve the value: \n$e\n";
        }
    
    });
} catch (Throwable $e) {
    echo "I successfully caught the missed exception in Worker 1:\n";
    echo " " . $e->getMessage() . "\n";
}
```


## Getting Started

Install phasync via Composer and start enhancing your PHP applications with powerful asynchronous capabilities:

```bash
composer require phasync/phasync
```


## License

*phasync* is open-sourced software licensed under the MIT license.