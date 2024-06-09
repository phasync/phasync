# Using phasync in existing projects

[Back to README.md](../README.md)

The phasync framework is designed to enable to use concurrency within your existing code base, without worrying about the framework "taking over" the entire application. Whenever you work with phasync, you use the `phasync::run()` function to work with coroutines. When this function returns, you can be certain that all coroutines created inside your code has completed their tasks and your application will continue operating as you would expect.

```php
function some_existing_func() {
    return phasync::run(function() {
        // Code inside this function can leverage concurrency and async IO.
    });  
}
```

## Making your application "coroutine aware"

Since **phasync** provides a very different style of async programming, using coroutines instead of promises, it is possible to make existing code coroutine aware. This means that your code will take advantage of concurrency capabilities whenever it is used inside a coroutine, and if the code is used outside of coroutines - they will still work as normally.

## Asynchronous IO in coroutines

Since coroutines look completely like ordinary code, without any promises and `->then()` chains, we need a system to allow other tasks to continue their work. There are just a few primary ways to do this with phasync:

 * Whenever you wish to read from a stream resource / file, you should call `phasync::readable($stream)`. This will pause the coroutine until the `$stream` resource has data available.
 * Whenever you wish to write to a stream resource / file, you should call `phasync::writable($stream)`. This will pause the coroutine until the `$stream` resource will accept a write operation without blocking the entire application.
 * Whenever you need to wait for something, perhaps because you check the database or are waiting for some file to appear, you should use `phasync::sleep()` instead of `usleep()` or `sleep()` between every check.
 
 These functions ensures that other coroutines are allowed to perform some work, instead of having the server kernel pause your entire application. The functions have no return value, and they work both inside **phasync** and outside of phasync. You can safely update your codebase and put `phasync::readable($fp)` before any call to `fread()` or similar. This ensures that if a coroutine is calling some functionality in your application codebase, the coroutine will take advantage of phasync to allow other coroutines to work.

### `phasync::readable(resource $stream)`

This function is *safe to call anywhere* you are about to perform a stream read operation (via `fgets()`, `fread()`, `fgetcsv()` etc.). Example:

```php
function get_countries(): array {
    $countries = [];
    $fp = fopen('countries.txt', 'r');

    while (!feof($fp)) {
        phasync::readable($fp); // Allow other coroutines to run until reading from `$fp` won't block.
        $countries[] = fgetcsv($fp);
    }
    fclose($fp);
}
```

### `phasync::writable(resource $stream)`

This function is *safe to call anywhere* you are about to perform a stream write operation (via `fwrite()`, `fputs()`, `fputcsv()` etc.). Example:

```php
function log_something(string $text): void {
    $fp = fopen('access.log', 'a');
    phasync::writable($fp); // Allow other coroutines to run until writing to `$fp` won't block.
    fwrite($fp, $text . "\n");
    fclose($fp);
}
```
