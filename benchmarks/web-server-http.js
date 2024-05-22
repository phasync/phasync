const http = require('http');

const server = http.createServer((req, res) => {
    // Prepare the HTTP response
    const response = 'Hello, world!';
    res.writeHead(200, {
        'Content-Type': 'text/plain',
        'Content-Length': response.length,
        'Connection': 'close'
    });
    res.end(response);
});

server.listen(8080, '0.0.0.0', () => {
    console.log('Server is listening on port 8080');
});
