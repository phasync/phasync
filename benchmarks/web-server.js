const net = require('net');

const server = net.createServer((socket) => {
    socket.setNoDelay(true);
    socket.setKeepAlive(false);
    socket.on('data', (data) => {
        // Simulate reading the request
        const request = data.toString();

        // Prepare the HTTP response
        const response = `HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Type: text/plain\r\nContent-Length: 13\r\n\r\nHello, world!`;

        // Write the response to the client
        socket.write(response, () => {
            // Close the socket after the response has been sent
            socket.end();
        });
    });

    socket.on('error', (err) => {
        console.error('Socket error:', err);
    });
});

server.on('error', (err) => {
    console.error('Server error:', err);
});

server.listen(8080, () => {
    console.log('Server is listening on port 8080');
});
