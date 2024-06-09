# Build a Simple Asynchronous Web Server

[Back to README.md](../README.md)

This document guides you through the creation of a simple asynchronous web server in PHP using the phasync library. We will describe to you the *low-level way* to do it, using pure PHP functions, and *only* use phasync for the asynchronous functionality. This is meant to show you how *phasync* focuses entirely on the asynchronous programming aspect, and does not in any way abstract away the PHP programming language any more than needed.

Web servers can handle multiple requests simultaneously through several approaches:

 * *Multiple Processes*: Each process handles one request. After sending the response, it waits for a new request.
 * *Multiple Threads*: A single process uses multiple threads, where each thread handles one request and waits for another upon completion.
 * *Single Process Asynchronous I/O*: This method utilizes a single process that can accept a request and, if during the response preparation it needs to wait for disk or network I/O (like a database operation), it will start handling another request. If there is no I/O blocking, the request can be responded to very quickly, making this model highly scalable.

## Asynchronous Programming Models

Asynchronous I/O in web servers can be implemented using one of two primary programming models:

 1. *Promises and Event-Driven Architecture*: Used by Node.js, ReactPHP, and amphp. This model involves writing code that registers callbacks for I/O operations. When an I/O operation blocks the execution, the callback is queued to resume once the I/O is ready.

 2. *Coroutines and Green Threads*: This model allows you to write code as if it were synchronous, without manually registering callbacks. Instead, the code execution is automatically suspended when waiting for I/O and resumes when it becomes available. This is similar to how languages like Go and, to some extent, C# handle asynchronous I/O.

By utilizing the phasync library, we can leverage PHP's capabilities to implement efficient asynchronous I/O, enhancing the scalability and performance of web applications.

## How a Web Server Operates

A web server is a software system that is continuously listening for incoming HTTP requests on designated TCP ports. Commonly, port 80 is used for HTTP traffic and port 443 for HTTPS, which is the secure version of the protocol. Here’s a step-by-step breakdown of the web server's operations:

 1. *Listening on Ports*: The web server listens on TCP ports (typically port 80 for HTTP and port 443 for HTTPS). This setup is crucial for the server to be reachable by web browsers or other client applications.

 2. *Establishing Connections*: When a client, such as a web browser, attempts to access a resource on the server, it initiates a TCP connection to the server’s IP address on the specified port. The client sends a TCP “SYN” packet to start the connection setup.

 3. *Connection Backlog*: The server’s operating system receives these initial connection requests and places them in a connection backlog. The backlog queue holds all pending connections until the web server software is ready to process them. The size of this queue can be configured and determines how many requests can wait in line during high traffic scenarios.

 4. *Accepting Connections*: The web server software periodically checks this backlog and accepts new connections. Upon acceptance, the operating system allocates a socket for the connection. In PHP, and many other programming environments, this socket acts like any other stream resource (similar to file handles), through which data can be read from and written to.

 5. *Handling HTTP Requests*:

     * *Request Headers*: Once a connection is established and accepted, the web server reads the HTTP request starting with the headers. The request headers contain the request line (method, URI, and HTTP version), followed by various headers that include metadata about the request (like content type, cookies, and caching directives).
     * *Request Body*: If the HTTP method supports a body (like POST or PUT), the server then reads the body of the request. This part of the request can contain data such as form inputs, file uploads, or JSON/XML payloads.
     
 6. *Processing Requests*: After the complete request is read, the web server processes it according to the specified URI and method. This process might involve retrieving static content from the file system, generating dynamic content through server-side scripts, or querying a database.

 7. *Sending Responses*: Once the request has been processed, the server constructs an HTTP response. This response includes a status line (status code and phrase), headers (like content type and cookies), and often a response body. The response is then sent back to the client through the same socket connection.

 8. *Connection Closure*: Depending on the headers (particularly Connection: keep-alive or Connection: close), the connection may either be kept open for further requests or closed immediately after the response is sent.

## Begin Coding

First you need to setup a basic PHP project. I assume you have done this many times, but here is the outline:

```bash
mkdir my-web-server
cd my-web-server
composer init  # Follow the prompts to set up the application
composer require phasync/phasync  # Install phasync
```

Start by creating the `server.php` file:

```php
<?php
require('vendor/autoload.php');

// Set up the socket server on port 8080
$ctx = stream_context_create([
    'socket' => [
        'backlog' => 511,  // Configure the kernel backlog size
        'so_reuseport' => true,  // Allow reconnection to a recently closed port
    ]
]);

$server = stream_socket_server('tcp://0.0.0.0:8080', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
```

## Start Accepting Requests

To effectively handle incoming requests asynchronously, use the `phasync` library to manage concurrent connections without blocking server operations:

```php
phasync::run(function() use ($server) {
    echo "Server is accepting connections...\n";

    while ($client = stream_socket_accept(phasync::readable($server), 3600, $peerName)) {
        // At this point, a connection from a client (browser) that connected to http://localhost:8080/ on your computer has been accepted.
        echo "Received a connection from $peerName\n";

        // Handle the HTTP request here
        // For example, read data, process it, and send a response

        // Ensure we close the client connection when done
        fclose($client);
    }
});
```

### Explanation

 * *`phasync::readable($server)`*: This function is pivotal in the asynchronous operation. It marks the server socket as a point of interest for incoming connections. When the server socket is ready to accept a new connection (i.e., it's "readable"), this function signals that the coroutine should resume at this line. It essentially blocks the coroutine—not the whole server—until a new client is ready to be accepted.

 * *Handling the request*: Within the while loop, after a client connection is accepted, you should include logic to read the incoming HTTP request, process it according to your application’s needs (e.g., fetching data, performing calculations, interacting with databases), and then generate and send an HTTP response back to the client.

 * *Concurrency management*: By using `phasync::readable`, you ensure that this coroutine pauses at the point of waiting for a new connection, allowing other coroutines or operations to run concurrently. This non-blocking behavior is crucial for maintaining high performance and responsiveness, particularly under heavy loads or numerous concurrent requests.

This setup is foundational and can be extended to support various server functions, such as serving web content, handling API requests, or managing email communications. Each type of service may require additional configuration and handling logic specific to the data format and expected interactions.

## Start Parsing HTTP Requests

Since we don't want parsing HTTP requests to interfere with the process of accepting connections, we will launch each client connection as a new coroutine. We'll create a function `handle_connection($client, string $peerName)` which will be launched in our loop:

```php
phasync::run(function() use ($server) {
    echo "Server is accepting connections...\n";

    while ($client = stream_socket_accept(phasync::readable($server), 3600, $peerName)) {
        // At this point, a connection from a client (browser) that connected to http://localhost:8080/ on your computer has been accepted.
        // Launch a coroutine to handle the connection:
        phasync::go(handle_connection(...), args: [$client, $peerName]);
    }
});

function handle_connection($client, string $peerName): void {
    echo "Received a connection from $peerName\n";

    // Handle the HTTP request here
    // For example, read data, process it, and send a response

    fclose($client);
}
```

In order to parse the HTTP request, we first need to read a chunk of data from the client. This works the same way as if you were reading from a file opened with `fopen($client, 'r')`. Let's update the `handle_connection` function:

```php
function handle_connection($client, string $peerName): void {
    // Read a large chunk of data from the client
    $buffer = fread(phasync::readable($client), 65536);
    if ($buffer === false || $buffer === '') {
        echo "$peerName: Unable to read request data or connection closed\n";
        fclose($client);
        return;
    }

    // Split the request into headers and body (if any)
    $parts = explode("\r\n\r\n", $buffer, 2);
    $head = $parts[0];
    $body = $parts[1] ?? '';

    // Split the head into individual lines
    $headerLines = explode("\r\n", $head);

    // Display the received HTTP request
    echo "$peerName: Received an HTTP request:\n";
    foreach ($headerLines as $headerLine) {
        echo "  $headerLine\n";
    }

    // Example response preparation and sending
    $response   = "HTTP/1.1 200 OK\r\n"
                . "Connection: close\r\n"
                . "Content-Type: text/html\r\n"
                . "Date: " . gmdate('r') . "\r\n"
                . "\r\n"
                . "<html><body>Hello, World!</body></html>";

    fwrite(phasync::writable($client), $response);

    fclose($client);
}
```

That's it!

## Final Thoughts on Developing a Secure Web Server

When advancing from a simple web server to a production-ready implementation, it's crucial to address potential security vulnerabilities systematically. While it is entirely feasible to develop a secure server, the complexity of web protocols and security risks means that attention to detail is critical. Consider using established servers like `nginx` or `Apache` as a reverse proxy to handle incoming HTTP requests and manage the more complex aspects of web traffic and security. Here are some essential security practices:

### 1. Secure File Access

When serving files from the filesystem, ensure that the request cannot traverse outside of the designated web root directory:

```php
$filePath = realpath($webRoot . $requestPath);
if (!\str_starts_with($filePath, $webRoot . '/')) {
    // This path traversal attempt is invalid and potentially malicious
    echo "Access denied.";
    return;
}
```

This snippet prevents directory traversal attacks by ensuring that the resolved path starts with the web root directory.

### 2. Limit Request Header Size

To protect against buffer overflow attacks or attempts to exhaust server resources, limit the size of incoming request headers. We did it in the above script, simply by reading at most 65536 bytes. If the header is not terminated with `\r\n\r\n`, then the request header is too large and you should close the connection.

### 3. Run Server with Non-privileged User

Never run your web server with root privileges to minimize the risks associated with potential security breaches. If the server needs to bind to privileged ports (like 80 or 443), drop privileges immediately after opening the socket:

```php
$server = stream_socket_server('tcp://0.0.0.0:80', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
$uid = posix_getpwnam('www-data'); // Or another non-privileged user
if ($uid === false) {
    echo "Unknown user 'www-data'\n";
    exit(1);
}
if (!posix_setuid($uid['uid'])) {
    echo "Unable to set user id to 'www-data'\n";
    exit(1);
}
```

This snippet ensures that after the server binds to a privileged port, it operates under a non-privileged user account.

### Additional Security Tips

 * *Implement Rate Limiting*: To prevent denial-of-service attacks, consider adding rate limiting to restrict how often a client can make requests within a certain time period. You can use the `phasync\Util\RateLimiter` class to achieve this.

 * *Use HTTPS*: Always use TLS/SSL to encrypt data transmitted between the server and clients. The reverse proxy does an excellent job at handling this for you.

 * *Regularly Update Dependencies*: Keep all server software and dependencies up-to-date to protect against known vulnerabilities.
