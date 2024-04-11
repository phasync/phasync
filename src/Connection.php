<?php
namespace phasync;

use Evenement\EventEmitter;
use LogicException;

class Connection extends EventEmitter {
    public const CLOSE_EVENT = 'close';
    private readonly ConnectionOptions $options;
    private mixed $socket = null;
    public readonly ?string $peerName;

    public function __construct($socket, string $peerName=null, array|ConnectionOptions $options=null) {
        $this->socket = $socket;
        $this->peerName = $peerName;
        $this->options = ConnectionOptions::create($options);

        stream_set_blocking($this->socket, false);
        stream_set_timeout($this->socket, 0, 500000);
        if ($this->options->chunkSize !== null) {
            stream_set_chunk_size($this->socket, $this->options->chunkSize);
        }
        if ($this->options->readBufferSize !== null) {
            stream_set_read_buffer($this->socket, $this->options->readBufferSize);
        }
        if ($this->options->writeBufferSize !== null) {
            stream_set_read_buffer($this->socket, $this->options->writeBufferSize);
        }
    }

    public function read(int $length=8192): string {
        $limit = 10;
        $result = null;
        while (is_resource($this->socket)) {
            if (0 === --$limit) {
                throw new ReadException("Reading from socket failed");
            }
            $this->assertConnected();
            Loop::readable($this->socket);
            $result = \fread($this->socket, $length);
            if (null !== $result) {
                return $result;
            }
            Loop::sleep(0.1);
        }
        $this->close();
        throw new DisconnectedException("Disconnected while reading");
    }

    public function readAll(): string {
        $this->assertConnected();
        $result = '';
        while (is_resource($this->socket) && !$this->eof()) {
            $result .= $this->read();
        }
        $this->assertConnected();
        return $result;
    }

    public function readLine(int $length = null, string $ending=null): string {
        $this->assertConnected();
        $limit = 10;
        $result = null;
        while (is_resource($this->socket)) {
            if (0 === --$limit) {
                throw new ReadException("Reading from socket failed");
            }
            Loop::readable($this->socket);
            $result = \stream_get_line($this->socket, $length ?? $this->options->lineLength, $ending ?? $this->options->lineEnding);
            if ($result !== null) {
                return $result;
            }
            Loop::sleep(0.1);
        }
        $this->close();
        throw new DisconnectedException("Disconnected while reading");
    }

    public function write(string $data, ?int $length = null): int {
        $limit = 10;
        $result = null;
        while (is_resource($this->socket)) {
            if (0 === --$limit) {
                throw new ReadException("Writing to socket failed");
            }
            $this->assertConnected();
            Loop::writable($this->socket);
            $result = \fwrite($this->socket, $data, $length);
            if (null !== $result) {
                return $result;
            }
            Loop::sleep(0.1);
        }
        $this->close();
        throw new DisconnectedException("Writing to socket failed");
    }

    public function eof(): bool {
        $this->assertConnected();
        return feof($this->socket);
    }

    public function close(): void {
        if ($this->socket === null) {
            throw new LogicException("Already closed");
        }
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
        $this->emit(self::CLOSE_EVENT);
    }

    private function assertConnected() {
        if (!is_resource($this->socket)) {
            throw new DisconnectedException("Socket disconnected");
        }
    }
}