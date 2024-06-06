<?php

namespace phasync\Server;

use Evenement\EventEmitter;
use phasync\Legacy\Loop;
use phasync\ReadException;

class TcpConnection extends EventEmitter
{
    public const CLOSE_EVENT = 'close';
    private readonly TcpConnectionOptions $options;
    private mixed $socket = null;
    public readonly ?string $peerName;

    // Buffering is needed for readLine()
    private string $readBuffer = '';

    public function __construct($socket, ?string $peerName=null, array|TcpConnectionOptions|null $options=null)
    {
        $this->socket   = $socket;
        $this->peerName = $peerName;
        $this->options  = TcpConnectionOptions::create($options);

        \stream_set_blocking($this->socket, false);
        \stream_set_timeout($this->socket, 0, 500000);

        if (null !== $this->options->readBufferSize) {
            \stream_set_read_buffer($this->socket, $this->options->readBufferSize);
        }

        if (null !== $this->options->chunkSize) {
            \stream_set_chunk_size($this->socket, $this->options->chunkSize);
        }

        if (null !== $this->options->writeBufferSize) {
            \stream_set_write_buffer($this->socket, $this->options->writeBufferSize);
        }
    }

    public function __destruct()
    {
        if (null !== $this->socket) {
            $this->close();
        }
    }

    public function isClosed(): bool
    {
        return !\is_resource($this->socket);
    }

    /**
     * Fills the buffer up to $length bytes and returns the buffer without
     * draining it. To drain the buffer you should use {@see TcpConnection::read()}
     */
    public function peek(int $length=32768): string
    {
        if ($length <= 0) {
            throw new \LogicException("Can't read only 0 bytes");
        }

        if ($this->eof()) {
            return $this->readBuffer;
        }

        if (\strlen($this->readBuffer) < $length) {
            Loop::readable($this->socket);

            if ($this->eof()) {
                return $this->readBuffer;
            }

            $chunk = \fread($this->socket, $length - \strlen($this->readBuffer));
            if (false === $chunk) {
                return $this->readBuffer;
            }
            $this->readBuffer .= $chunk;
        }

        return \substr($this->readBuffer, 0, $length);
    }

    public function read(int $length=8192): string
    {
        $limit = 10;
        if ($length <= 0) {
            throw new \LogicException("Can't read only 0 bytes");
        }
        while (\is_resource($this->socket)) {
            if (0 === --$limit) {
                throw new ReadException('Reading from socket failed');
            }
            $this->assertConnected();

            // We must check if the buffer contains enough data
            $readBufferLength = \strlen($this->readBuffer);
            // If the data is already in the buffer, no need to block or do anything
            if ($readBufferLength >= $length) {
                return $this->readFromBuffer($length);
            }

            // The read buffer is shorter than the length we want to read so let's try
            Loop::readable($this->socket);
            $chunk = \fread($this->socket, $length - $readBufferLength);
            if (false === $chunk) {
                if ('' !== $this->readBuffer) {
                    // We'll pretend the error didn't occur since we have buffered data
                    return $this->readFromBuffer($length);
                }
                // Connection has failed
                break;
            }

            $this->readBuffer .= $chunk;

            if ('' !== $this->readBuffer) {
                return $this->readFromBuffer($length);
            }
            // Small delay in case an fread failed.
            Loop::sleep(0.1);
        }
        $this->close();
        throw new DisconnectedException('Disconnected while reading');
    }

    public function readAll(): string
    {
        $this->assertConnected();
        $result = '';
        while (\is_resource($this->socket) && !$this->eof()) {
            $chunk = $this->read();
            if ('' === $chunk) {
                Loop::sleep(0.1);
                continue;
            }
            if (false === $chunk) {
                $this->close();

                return $result;
            }
            $result .= $this->read();
        }

        return $result;
    }

    /**
     * Read a line from the stream. This function will cause buffering up to
     * $length bytes, regardless of the configured read buffer size in options.
     *
     * If the buffer grows to $length bytes and a line is not available, the function
     * returns $length bytes with no new line character.
     *
     * @param int|null $length
     *
     * @throws DisconnectedException
     * @throws ReadException
     * @throws \LogicException
     * @throws \FiberError
     * @throws \Throwable
     * @throws \InvalidArgumentException
     */
    public function readLine(int $length = 8192, ?string $ending=null): ?string
    {
        $this->assertConnected();
        $limit   = 10;
        $pattern = null === $ending ? '/^(.*?)(\r\n|\r|\n)/s' : '/^(.*?)' . \preg_quote($ending) . '/s';

        while (\is_resource($this->socket)) {
            if (0 === --$limit) {
                throw new ReadException('Reading from socket failed');
            }

            if ($this->eof()) {
                return '';
            }

            if (\preg_match($pattern, $this->readBuffer, $matches)) {
                // Buffer contains a line
                return $this->read(\strlen($matches[0]));
            }

            if (\strlen($this->readBuffer) >= $length) {
                // Buffer can't grow anymore and we have no line
                return $this->readFromBuffer($length);
            }

            // Fill the buffer
            Loop::readable($this->socket);
            $chunk = \fread($this->socket, $length - \strlen($this->readBuffer));
            if (false === $chunk || '' === $chunk) {
                if (\feof($this->socket)) {
                    $temp             = $this->readBuffer;
                    $this->readBuffer = '';

                    return $temp;
                }
                continue; // handle non-blocking empty read
            }
            $this->readBuffer .= $chunk;
        }
        $this->close();
        throw new DisconnectedException('Disconnected while reading');
    }

    public function write(string $data, ?int $length = null): int
    {
        $limit  = 10;
        $result = null;
        while (\is_resource($this->socket)) {
            if (0 === --$limit) {
                throw new ReadException('Writing to socket failed');
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
        throw new DisconnectedException('Writing to socket failed');
    }

    public function eof(): bool
    {
        $this->assertConnected();

        return '' === $this->readBuffer && \feof($this->socket);
    }

    public function close(): void
    {
        if (null === $this->socket) {
            throw new \LogicException('Already closed');
        }
        if (\is_resource($this->socket)) {
            \fclose($this->socket);
        }
        $this->socket = null;
        $this->emit(self::CLOSE_EVENT);
    }

    protected function readFromBuffer(int $length): string
    {
        $result           = \substr($this->readBuffer, 0, $length);
        $length           = \strlen($result);
        $this->readBuffer = \substr($this->readBuffer, $length);

        return $result;
    }

    protected function assertConnected()
    {
        if (!\is_resource($this->socket)) {
            throw new DisconnectedException('Socket disconnected');
        }
    }
}
