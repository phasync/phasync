<?php
namespace phasync\Server;

use Closure;
use phasync\Legacy\Loop;
use Throwable;

class HttpServer {

    private TcpServer $server;

    public function __construct(string $ip, int $port) {
        $this->server = new TcpServer($ip, $port);
    }

    public function run(Closure $requestHandler) {
        $this->server->run(function(TcpConnection $connection) use ($requestHandler) {
            while (!$connection->isClosed()) {
                $httpConnection = new HttpConnection($connection);
                try {
                    $requestHandler($httpConnection);
                } catch (Throwable $e) {
                    /**
                     * When an unhandled exception occurred in the HttpConnection
                     * we don't know where in the response it happened, so we'll 
                     * simply try to respond with an internal server error and close
                     * the connection.
                     */
                    Loop::handleException($e);
                    if (!$connection->isClosed()) {
                        $connection->close();
                        return;
                    }
                }

                if (
                    $httpConnection->getProc
                    $httpConnection->getResponseHeaderValue('connection') !== 'keep-alive'
                    && !$connection->isClosed()
                ) {
                    $connection->close();
                    return;
                }
            }
        });
    }

    public function close(): void {
        $this->server->close();
    }
}