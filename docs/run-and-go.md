# `phasync::run()` and `phasync::go()`

[Back to README.md](../README.md)

These are the two most important functions to use. The `phasync::run()` function creates an event loop which allows coroutines to run concurrently, so that when one coroutine is waiting for IO operation or is just pausing - then other coroutines are automatically resumed to do work.

## `phasync::run(Closure $fn, ?array $args=[], ?ContextInterface $context=null): mixed`

The `phasync::run()` creates the first coroutine in your program. Other coroutines created inside this context will be associated with this run context. If any coroutines in the context throw exceptions that are not captured, this function will throw that exception.

```php
phasync::run(function() {
     echo "Hello, ";
});

echo phasync::run(function() {
    return "World!\n";
});
```

While the above example is very trivial, it demonstrates how easy it is to begin taking advantage of asynchronous IO in PHP. To elaborate on the example, let's imagine there are a few functions in your application that's' responsible for reading CSV files and returning them as normal arrays:

## `phasync::go(Closure $fn, array $args=[], int $concurrent = 1, ?ContextInterface $context=null): Fiber)`

The other function that is essential, is the `phasync::go()` function. It does essentially the same as `phasync::run()`, but it does *not prevent the running of the parent coroutine*. This function can only be used from inside a `phasync::run()` context.

Example:

```php
phasync::run(function() {
    // Count to 3:
    phasync::go(function() {
        for ($i = 1; $i <= 3; $i++) {
            echo "Counting: 0 < $i <= 3\n";
        }
    });
    // Count from 4 to 6
    phasync::go(function() {
        for ($i = 4; $i <= 6; $i++) {
            echo "Counting: 3 < $i <= 6\n";
        }
    });
});
```

You might have expected it to count 1,4,2,5,3,6 or something, but no... The huge performance advantage of async coroutines is that they depend on YOU to say that the other coroutine is allowed to run. This is why languages such as Go are so good at working with IO. They aren't continously interrupted by the operating system kernel for task and thread switching. The program actually performs work all the time, just pausing the work in one part of the application when that part needs to wait for an API response or a disk operation.

To "fix" the above code so that both counters run concurrently, you can use the `phasync::sleep()` function. When you call `phasync::sleep()`, it signals to `phasync` that *if there is other work, you can do that now*.

```php
phasync::run(function() {
    // Count to 3:
    phasync::go(function() {
        for ($i = 1; $i <= 3; $i++) {
            echo "Counting: 0 < $i <= 3\n";
            phasync::sleep();
        }
    });
    // Count from 4 to 6
    phasync::go(function() {
        for ($i = 4; $i <= 6; $i++) {
            echo "Counting: 3 < $i <= 6\n";
            phasync::sleep();
        }
    });
});
```

As you can see, the changes to how you normally program is minimal. Of course, it would be much better if you had disk or network tasks to perform, instead of just context switching this way. The great thing about disk or network tasks, is that the *operating system kernel* is doing the hard work on a different CPU core. For normal PHP applications, this means that the entire PHP application sits idle and waits for the kernel to finish the work.

To finish off, here is an example where you get a real benefit from `phasync`. Let's say you already have this function in your big application:

```php
function read_countries_csv(): array {
    $result = [];
    $fp = fopen('countries.csv', 'r');    
    while (($data = fgetcsv($handle, 1000, ",")) !== false) {
        $result[] = $data;
    }
    fclose($handle);
    return $result;
}
```

Every single time the `fgetcsv` function is run your application may be paused doing nothing.

Let's put the function inside the example code from above:

```php
phasync::run(function() {
    // Count to 3:
    phasync::go(function() {
        for ($i = 1; $i <= 3; $i++) {
            echo "Counting: 0 < $i <= 3\n";
            read_countries_csv();
        }
    });
    // Count from 4 to 6
    phasync::go(function() {
        for ($i = 11; $i <= 3; $i++) {
            echo "Counting: 3 < $i <= 6\n";
            read_countries_csv();
        }
    });
});
```

Now, this won't automagically become asynchronous. You still need to do one of two things. Either install `phasync/file-streamwrapper`, and voila! the code is asynchronous, or change your legacy function like this:

```php
function read_countries_csv(): array {
    $result = [];
    $fp = fopen('countries.csv', 'r');    
    \stream_set_blocking($fp, false);
    phasync::readable($fp);
    while (($data = fgetcsv($handle, 1000, ",")) !== false) {
        $result[] = $data;
        phasync::readable($fp);
    }
    fclose($handle);
    return $result;
}
```

The function will still work like normal in the rest of your application, but it will be asynchronous whenever you use it inside coroutines.
