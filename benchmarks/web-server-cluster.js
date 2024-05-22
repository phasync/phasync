const cluster = require('cluster');
const http = require('http');
const os = require('os');
const numCPUs = 4;

if (cluster.isMaster) {
    // Fork workers
    for (let i = 0; i < numCPUs; i++) {
        cluster.fork();
    }

    cluster.on('exit', (worker, code, signal) => {
        console.log(`Worker ${worker.process.pid} died`);
        cluster.fork(); // Replace the dead worker
    });
} else {
    // Workers can share any TCP connection
    const server = http.createServer((req, res) => {
        const response = 'Hello, world!';
        res.writeHead(200, {
            'Content-Type': 'text/plain',
            'Content-Length': response.length,
            'Connection': 'close'
        });
        res.end(response);
    });

    server.on('connection', (socket) => {
        socket.setNoDelay(true); // Enable TCP_NODELAY
    });

    server.listen(8080, () => {
        console.log(`Worker ${process.pid} started`);
    });
}
