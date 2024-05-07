# phasync semantics

This document describes the guiding principles of phasync, for any functionality
that is provided by the core library.


## Don't expose the event loop

Users of this library should not have to interact directly with the event loop.
A coroutine should operate as if it is completely synchronous, and interaction
with the event loop for pausing, context switching and blocking should be abstracted
away into support libraries such as an HTTP client, a sleep() function or other
functions that are generally blocking.


## Use core functionality whenever possible

Functionality should rely on the core mechanisms provided via the `phasync` class
to provide more advanced functionality. For example, a WaitGroup class should rely
on phasync::raiseFlag() and phasync::awaitFlag() internally, to ensure the most
efficient implementation and proper exception propagation.

Another example is when providing for example multi curl functionality using
curl_multi_exec(). Whenever a fiber is being suspended while waiting for a
curl handle to complete, a good approach would be to suspend the fiber using
phasync::awaitFlag($curlHandle) and then to use a separate fiber that will raise
the flag using phasync::raiseFlag($curlHandle) when the CurlHandle is ready. Other
approaches for suspending fibers is prone to deadlocks in mysterious ways. Using 
the global flags API is essentially cost free, and if the $curlHandle is garbage
collected then the suspended fiber will be automatically resumed.


## Exceptions must be thrown up the call stack, even when thrown inside coroutines

When a coroutine launches another coroutine, if the exception is not properly handled
within the coroutine, it must be thrown to any other coroutine that uses phasync::await()
to wait for a result. If there are no other coroutines awaiting the result, the exception
must be thrown in the coroutine that created the child coroutine or in the first ancestor
coroutine.


## Avoid garbage collection and callbacks

Implementations should not rely on callback scheduling. This is to avoid excessive garbage
collection. Coroutines are only garbage collected when they complete, so it is more
efficient to use a single coroutine that contains a loop than invoking separate callbacks
on events.


## Avoid deadlock opportunities

Implementations should avoid situations where a deadlock can be created. For example, if 
a Channel consists of a Reader and a Writer object, then the Reader should automatically
be closed when the Writer is garbage collected - and vice versa. It will still be possible
to cause deadlock situations, but it should be intentional. The `phasync` library always
resumes pending coroutines after a timeout of 30 seconds occurs (except for coroutines
scheduled via phasync::sleep() for more than 30 seconds). If a longer timeout is required,
the library is expected to start over.


## Avoid relying on exception handlers

Coroutines should throw when needed, and unless the exception is handled at the top of the
call stack, the exception should cause the program to terminate.


## Prioritize simplicity

Functionality designed to support asynchronous programming should be simple and efficient.
It should avoid relying on abstractions such as encapsulating native PHP functionality
like stream resources inside custom APIs.