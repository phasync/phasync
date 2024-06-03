<?php

namespace phasync\Server;

use Evenement\EventEmitter;
use phasync\IOException;
use phasync\Legacy\Loop;

/**
 * Asynchronous generic TCP server class for phasync.
 */
class TcpServer extends EventEmitter
{
    private string $ip;
    private int $port;
    public readonly TcpServerOptions $serverOptions;
    public readonly TcpConnectionOptions $connectionOptions;

    private mixed $context       = null;
    private mixed $socket        = null;
    private int $connectionCount = 0;

    public function __construct(string $ip, int $port, array|TcpServerOptions|null $serverOptions = null, array|TcpConnectionOptions|null $connectionOptions = null)
    {
        $this->ip                = $ip;
        $this->port              = $port;
        $this->serverOptions     = TcpServerOptions::create($serverOptions);
        $this->connectionOptions = TcpConnectionOptions::create($connectionOptions);

        $this->context = \stream_context_create([
            'ssl'    => [],
            'socket' => [
                'backlog'      => $this->serverOptions->socket_backlog,
                'ipv6_v6only'  => $this->serverOptions->socket_ipv6_v6only,
                'so_reuseport' => $this->serverOptions->socket_so_reuseport,
                'so_broadcast' => $this->serverOptions->socket_so_broadcast,
                'tcp_nodelay'  => true,
            ],
        ]);

        if ($this->serverOptions->connect) {
            $this->open();
        }
    }

    public function getAddress(): string
    {
        return $this->ip;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Close the listening socket.
     *
     * @throws DisconnectedException
     */
    public function close(): void
    {
        $this->assertOpen();
        \fclose($this->socket);
        $this->socket = null;
    }

    /**
     * Open the socket and start listening for connections.
     *
     * @throws \LogicException
     * @throws IOException
     */
    public function open(): void
    {
        if (!$this->isClosed()) {
            throw new \LogicException('tcp://' . $this->ip . ':' . $this->port . ' is already opened');
        }
        $errorCode    = null;
        $errorMessage = null;
        $socket       = \stream_socket_server(
            'tcp://' . $this->ip . ':' . $this->port,
            $errorCode,
            $errorMessage,
            $this->serverOptions->serverFlags,
            $this->context
        );

        [$this->ip, $this->port] = \explode(':', \stream_socket_get_name($socket, false));

        \stream_set_blocking($socket, false);

        if (false === $socket) {
            throw new IOException($errorMessage, $errorCode);
        }

        $this->socket = $socket;
    }

    /**
     * Returns true if the socket is open.
     */
    public function isClosed(): bool
    {
        return !\is_resource($this->socket);
    }

    /**
     * Check that the socket is open and fail with a DisconnectedException
     * if not.
     *
     * @throws DisconnectedException
     */
    private function assertOpen(): void
    {
        if ($this->isClosed()) {
            throw new DisconnectedException();
        }
    }

    /**
     * Run the server until it is closed.
     *
     * @throws DisconnectedException
     * @throws \FiberError
     * @throws \Throwable
     * @throws \Exception
     * @throws \InvalidArgumentException
     *
     * @return void
     */
    public function run(\Closure $connectionHandler)
    {
        return Loop::go(function () use ($connectionHandler) {
            while (!$this->isClosed() && ($connection = $this->accept())) {
                Loop::go($connectionHandler, $connection);
            }
            if ($this->isClosed() && !$connection->isClosed()) {
                $connection->close();
            }
        });
    }

    public function accept()
    {
        $this->assertOpen();

        echo "Accept called\n";
        // Block the Fiber until a stream is available via stream_select

        if (!\is_resource($this->socket)) {
            throw new \Exception('Server socket closed');
        }

        $peerName = null;
        while (\is_resource($this->socket)) {
            // limit the number of active connections being handled
            if ($this->connectionCount >= $this->serverOptions->maxConnections) {
                while ($this->connectionCount >= $this->serverOptions->maxConnections) {
                    // Context switch
                    Loop::yield();
                }
                continue;
            }
            Loop::readable($this->socket);

            // socket may have been closed by a call to $this->close() or an error
            if (\is_resource($this->socket)) {
                $socket = @\stream_socket_accept($this->socket, 0, $peerName);
                if ($socket) {
                    $connection = new TcpConnection($socket, $peerName, $this->connectionOptions);
                    ++$this->connectionCount;
                    $connection->on(TcpConnection::CLOSE_EVENT, function () {
                        --$this->connectionCount;
                    });

                    return $connection;
                }
            }
        }

        return null;
    }
}
