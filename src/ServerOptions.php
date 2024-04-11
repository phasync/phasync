<?php
namespace phasync;

use Charm\AbstractOptions;

class ServerOptions extends AbstractOptions {
    /**
     * Restrict how many concurrent connections will be allowed. When the connection
     * limit is reached, accepting new connections will block.
     */
    public int $maxConnections = 500;

    /**
     * Should the server automatically connect to the socket?
     */
    public bool $connect = true;

    /**
     * A bitmask field which may be set to any combination of socket creation flags.
     * {@see https://www.php.net/manual/en/function.stream-socket-server.php}
     */
    public int $serverFlags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

    /**
     * How many connections can be pending on the server socket? The default value
     * of 511 feels arbitrary, but was chosen because ReactPHP uses that.
     */
    public ?int $socket_backlog = 511;
    public bool $socket_ipv6_v6only = false;
    public bool $socket_so_reuseport = true;
    public bool $socket_so_broadcast = false;
}