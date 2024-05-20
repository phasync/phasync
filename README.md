# phasync: High-concurrency PHP

**Encouragement:** Have some fun! phasync is a really fun environment to experiment developing long running applications in. For example an HTTP server involves opening a stream_socket_server() and then using phasync::readable() on th socket before accepting new http requests. Further use phasync::readable() or phasync::writable() before reading or writing to sockets. Pass each socket to a coroutine created with phasync::go(). You can write PHP applications that streams data, make a websocket library or even long polling or EventSource servers - so you can push data to the browser.

Asynchronous programming should not be difficult. This is a new microframework for doing asynchronous programming in PHP. It tries to do for PHP, what the `asyncio` package does for Python, and what Go does by default. For some background from what makes *phasync* different from other asynchronous big libraries like *reactphp* and *amphp* is that *phasync* does not attempt to redesign how you program. *phasync* can be used in a single function, somewhere in your big application, just where you want to speed up some task by doing it in parallel.

> The article [What color is your function?](https://journal.stuffwithstuff.com/2015/02/01/what-color-is-your-function/) explains some of the approaches that have been used to do async programming in languages not designed for it. With Fibers, PHP 8.1 has native asynchronous IO built in. This library simplifies working with them, and is highly optimized for doing so.

*phasync* brings Go-inspired concurrency to PHP, utilizing native and ultra-fast coroutines to manage thousands of simultaneous operations efficiently. By leveraging modern PHP features like fibers, *phasync* simplifies asynchronous programming, allowing for clean, maintainable code that performs multiple tasks simultaneously with minimal overhead.

## ChatGPT assistance!

By pasting the `CHATBOT.txt` file into ChatGPT or your preferred chatbot, you can get assistance and ask questions about how to integrate `phasync` with your projects.

## Making your code coroutine friendly

*phasync* does not take over your application and force you to restructure it. It is simply an efficient tool to run functions simultaneously in a limited context. Exceptions are thrown as you would expect - but *WITHOUT* the dreaded `->then()` and `->catch()` stuff.

For example by sending multiple HTTP requests concurrently makes this is twice as fast:

```php
function do_some_requesting() {
    return phasync::run(function() {
        $httpClient = new phasync\HttpClient\HttpClient();
        return [
            // These use an internal coroutine via phasync::go()
            $httpClient->get('https://www.vg.no/'),
            $httpClient->get('https://github.com/')
        ];
    });    
}
```

You can even parallelize much more complex flows:

```php
function crawl_for_urls(string $baseUrl) {
    return phasync::run(function() {
        phasync::channel($reader, $writer);
        $client = new HttpClient;
        $queue = new SplQueue;
        $queue->enqueue($baseUrl);
        
        // Launch 3 parallel workers, each waiting for messages from
        // the `$reader` channel.
        for ($i = 0; $i < 3; $i++) {
            phasync::go(function() use ($reader, $client, $queue) {
                while ($url = $reader->read()) {
                    $body = (string) $client->get($url)->getBody();
                    foreach (extract_urls_from_body($body) as $foundUrl) {
                        $queue->enqueue($foundUrl);
                    }
                }
            });
        }
        $alreadyCrawled = [];
        while (!$queue->isEmpty()) {
            $nextUrl = $queue->dequeue();
            if (in_array($alreadyCrawled)) {
                continue;
            }
            $alreadyCrawled[] = $nextUrl;
            $writer->write($nextUrl);
        }
    });
}
```

## Easily make any existing code *phasync* aware

Transform how your applications handle IO-bound and CPU-intensive operations with non-blocking execution patterns. Whether building high-traffic web apps, data processing systems, or real-time APIs, *phasync* equips you with the tools to scale operations smoothly and reliably.

To make disk IO operations asynchronous, transparently within coroutines you can use:

```bash
composer require phasync/file-streamwrapper
```

This makes even loading of classes not block other coroutines and you can continue using functions like `file_get_contents()`, `file_put_contents()` etc.

If you wish to make network sockets asynchronous, you can follow the recipes below. You can safely use these methods outside of coroutines as well. They should not interfere with how your software works, but when the functions are used inside coroutines they will be using asynchronous IO to allow other coroutines work concurrently.

### Reading network or file streams

```php
// Instead of:
$chunk = fread($fp, 4096);

// Do this:
phasync::readable($fp);
$chunk = fread($fp, 4096);
```

### Writing files or network streams:

```php
// Instead of:
$chunk = fwrite($fp, "Some data");

// Do this:
phasync::writable($fp);
$chunk = fwrite($fp, "Some data");
```

### Waiting for network requests:

```php
// Instead of:
$resource = stream_socket_accept($socket);

// Do this:
phasync::readable($socket);
$resource = stream_socket_accept($socket);
```

### Performing an expensive blocking operation:

*NOTE!* the `phasync::idle()` only sleeps if there is NOTHING else that could be done. Effectively it will wait until your application has nothing else to do before running your slow function. Most of the time it will not sleep at all.

```php
// Instead of:
$n = fibonacci(32);

// Do this:
phasync::idle(0.1); // Wait at most 0.1 seconds for the application to become idle
$n = fibonacci(32);

// Instead of:
$files = glob("*.txt");

// Do this:
phasync::idle(0.1);
$files = glob("*.txt");
```


## Utilities

### Channels

Channels are used to communicate between coroutines. Channels are special primitives which are created with `phasync::channel($readableChannel, $writableChannel, $bufferSize=0)`. The readable channel has a `read()` method which will return a value written to the writable channel. If there is no data available, the coroutine will be suspended until a writer writes to the channel. The writable channel similarly has a `write(Serializable|scalar $value)` method which will suspend the coroutine if the buffer is full or if there is no reader that is waiting for data. The buffer size allows you to enqueue values inside the channel.

Channels are highly optimized and are able to immediately resume coroutines, so they can be used for efficient scheduling of work between coroutines. They automatically close the other channel whenever it is garbage collected, or if one side calls `$channel->close()`. The readable channel will return `null` when the channel is closed.

For example if you have one coroutine designed to write to the disk or to update the database, and 10 coroutines crawling a website you can do this:

```php
phasync::run(function() {
    phasync::channel($reader, $writer);

    // The logger coroutine
    phasync::go(function() use ($reader) {
        $fp = fopen('some-log.txt', 'a');
        while (null !== ($line = $reader->read())) {
            // this is non-blocking if you install phasync/file-streamwrapper
            fwrite($fp, trim($line) . "\n");
        }
        fclose($fp);
    });

    $writerNumber = 1;
    phasync::go(concurrent: 5, fn: function() use ($writer, $writerNumber) {
        $number = $writerNumber++;
        for ($i = 0; $i < 10; $i++) {
            $writer->write("From writer $writerNumber: This is message $i");
        }
    });
});
```

### WaitGroups

WaitGroups provides a small utility for allowing multiple different coroutines to complete their work. For example if you issue 10 simultaneous HTTP requests, you can use a WaitGroup to make sure all the 10 coroutines have completed their task.

Example:

```php
phasync::run(function() {
    $wg = new WaitGroup();

    phasync::go(concurrent: 5, fn: function() use ($wg) {
        $wg->add(); // Inform the WaitGroup that this coroutine will be performing some work
        try {
            // Do the work
            phasync::sleep(0.5);
        } finally {
            $wg->done();
        }
    });

    // Wait until the 5 coroutines have finished their work
    $wg->wait();
});
```

### Publisher

To guarantee delivery of messages and events to multiple coroutines, even if a coroutine is blocked, you can use a publisher. The publisher is an implementation of the publisher/subscriber, so any message written to the publisher will be received in order by all subscribers. Similar to channels, these are phasync primitives that you create with `phasync::publisher($delivery, $publisher)`.

Example:

```php
phasync::run(function() {
    phasync::publisher($source, $writeChannel);

    // Launch 10 subscribers:
    phasync::go(concurrent: 10, fn: function() use ($source) {
        $readChannel = $delivery->subscribe();
        while ($line = $readChannel->read()) {
            echo "Subscriber got " . trim($line) . "\n";
        }
    });

    $writeChannel->write("First");
    $writeChannel->write("Second");
});
```

## Work in progress

While the library seems to be stable, it has not been battle tested. We want enthusiasts to contribute in building tests, documentation and discussion around the architecture.

Please contribute; we want asynchronous tools to work with:

 * A `Process` class, for running background processes using `proc_open()`. With a `Process` class, we could use a child `php` process to run functions that can't be made non-blocking, such as directory scans, dns lookups and so on. It can also be used to scale the application to utilize multiple CPU cores. Another use for such a class, is to run the `sqlite3` command as a separate process, allowing asynchronous queries to an sqlite3 database.

 * An asynchronous `MySQL` driver built on top of `mysqli` which supports everything needed for asynchronous database access.

 * A `TcpServer` class, for developing fast and concurrent TCP servers using  `stream_socket_server`.
 
   A `TcpServer` class should make developing TCP servers a breeze. See `phasync\TcpServer` for the work in progress. Combined with Channels, WaitGroups and Publishers, a lot of powerful services can be designed.

   This class will be the foundation for various methods for serving phasync applications as standalone and concurrent applications; `HttpServer` or `FastCGIServer`.

 * A `TcpClient` class which would simplify developing clients for important systems like `redis` or `memcached` that are also asynchronous.

 * A `HttpClient` using `TcpClient` for asynchronous and concurrent requests using `curl_multi_init`.

 * A `http://` and `https://` and `file://` stream wrapper for making these asynchronous.


### Example: Asynchronous File Processing in a Web Controller

```php
<?php
require '../vendor/autoload.php';
use phasync\{run, go, file_get_contents};

class MyController {

    #[Route("/", "index")]
    public function index() {
        // Initiate the event loop within your existing controller method
        phasync::run(function() {
            // Process each text file asynchronously
            foreach (glob('/some/path/*.txt') as $file) {
                phasync::go(function() use ($file) {
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
require '../vendor/autoload.php';

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
