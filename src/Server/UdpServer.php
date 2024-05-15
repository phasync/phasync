<?php
namespace phasync\Server;

use phasync;
use phasync\IOException;

class UdpServer {

    private mixed $socket;
    private string $address;

    public function __construct(string $address) {
        // Create UDP socket using stream context
        $context = stream_context_create([
            'socket' => [
                'domain' => AF_INET,
                'type' => SOCK_DGRAM,
                'protocol' => SOL_UDP,
                'so_reuseaddr' => true, // Enable port reuse                
            ]
        ]);
        $this->socket = stream_socket_server($address, $error_code, $error_message, STREAM_SERVER_BIND, $context);
        if ($this->socket === false) {
            throw new IOException("Failed to create socket: $error_message", $error_code);
        }
        $this->address = stream_socket_get_name($this->socket, false);
        stream_set_blocking($this->socket, false);
    }

    public function getAddress(): string {
        return $this->address;
    }

    public function close(): void {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function send(string $message, string $toAddress=''): int|false {
        if (!$this->socket) {
            throw new IOException("Socket is closed");
        }
        phasync::writable($this->socket);
        return stream_socket_sendto($this->socket, $message, 0, $toAddress);
    }

    public function receive(string &$peer=null): ?string {
        if (!$this->socket) {
            throw new IOException("Socket is closed");
        }
        phasync::readable($this->socket);
        $data = stream_socket_recvfrom($this->socket, 65536, 0, $peer);
        if ($data === false) {
            return null;
        }
        return $data;
    }

}