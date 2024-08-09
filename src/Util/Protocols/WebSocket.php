<?php

namespace phasync\Util\Protocols;

use phasync\Internal\ObjectPoolInterface;
use phasync\Internal\ObjectPoolTrait;
use phasync\Util\StringBuffer;

final class WebSocket implements ObjectPoolInterface
{
    use ObjectPoolTrait;

    /**
     * WebSocket opcode types.
     */
    public const OPCODE_CONTINUATION = 0x0;
    public const OPCODE_TEXT         = 0x1;
    public const OPCODE_BINARY       = 0x2;
    public const OPCODE_CLOSE        = 0x8;
    public const OPCODE_PING         = 0x9;
    public const OPCODE_PONG         = 0xA;

    public const OPCODES = [
        self::OPCODE_CONTINUATION => 'CONTINUATION',
        self::OPCODE_TEXT         => 'TEXT',
        self::OPCODE_BINARY       => 'BINARY',
        self::OPCODE_CLOSE        => 'CLOSE',
        self::OPCODE_PING         => 'PING',
        self::OPCODE_PONG         => 'PONG',
    ];

    public bool $fin           = true;
    public bool $rsv1          = false;
    public bool $rsv2          = false;
    public bool $rsv3          = false;
    public int $opcode         = self::OPCODE_TEXT;
    public bool $mask          = false;
    public int $payloadLen     = 0;
    public ?string $maskingKey = null;
    public string $payload     = '';
    private bool $pooled       = false;

    public function returnToPool(): void
    {
        $this->assertNotPooled();
        $this->pooled                       = true;
        $this->fin                          = true;
        $this->rsv1                         = false;
        $this->rsv2                         = false;
        $this->rsv3                         = false;
        $this->opcode                       = self::OPCODE_TEXT;
        $this->mask                         = false;
        $this->payloadLen                   = 0;
        $this->maskingKey                   = null;
        $this->payload                      = '';
        self::$pool[self::$instanceCount++] = $this;
    }

    /**
     * Parse a Frame instance from a StringBuffer object.
     *
     * @throws \OutOfBoundsException
     */
    /**
     * Parse a Frame instance from a StringBuffer object.
     *
     * @throws \OutOfBoundsException
     */
    public static function parse(StringBuffer $buffer): ?WebSocket
    {
        $header = $buffer->readFixed(2);
        if (null === $header) {
            return null;
        }

        $instance   = self::create();
        $firstByte  = \ord($header[0]);
        $secondByte = \ord($header[1]);

        $instance->fin    = ($firstByte & 0x80) !== 0;
        $instance->rsv1   = ($firstByte & 0x40) !== 0;
        $instance->rsv2   = ($firstByte & 0x20) !== 0;
        $instance->rsv3   = ($firstByte & 0x10) !== 0;
        $instance->opcode = $firstByte & 0x0F;
        $instance->mask   = ($secondByte & 0x80) !== 0;
        $payloadLen       = $secondByte & 0x7F;

        if (126 === $payloadLen) {
            $extendedPayloadLen = $buffer->readFixed(2);
            if (null === $extendedPayloadLen) {
                $buffer->unread($header);

                return null;
            }
            $instance->payloadLen = \unpack('n', $extendedPayloadLen)[1];
        } elseif (127 === $payloadLen) {
            $extendedPayloadLen = $buffer->readFixed(8);
            if (null === $extendedPayloadLen) {
                $buffer->unread($header);

                return null;
            }
            $instance->payloadLen = \unpack('J', $extendedPayloadLen)[1];
        } else {
            $instance->payloadLen = $payloadLen;
        }

        if ($instance->mask) {
            $instance->maskingKey = $buffer->readFixed(4);
            if (null === $instance->maskingKey) {
                if (isset($extendedPayloadLen)) {
                    $buffer->unread($extendedPayloadLen);
                }
                $buffer->unread($header);

                return null;
            }
        }

        $instance->payload = $buffer->readFixed($instance->payloadLen);
        if (null === $instance->payload) {
            if ($instance->mask) {
                $buffer->unread($instance->maskingKey);
            }
            if (isset($extendedPayloadLen)) {
                $buffer->unread($extendedPayloadLen);
            }
            $buffer->unread($header);

            return null;
        }

        if ($instance->mask) {
            $instance->unmaskPayload();
        }

        return $instance;
    }

    /**
     * Create a new instance of Frame.
     */
    public static function create(): WebSocket
    {
        $instance         = self::popInstance() ?? new self();
        $instance->pooled = false;

        return $instance;
    }

    /**
     * Use {@see Frame::create()} to create an instance of Frame.
     *
     * @return void
     */
    private function __construct()
    {
    }

    public function setPayload(string $payload, int $opcode = self::OPCODE_TEXT): void
    {
        $this->assertNotPooled();
        $this->payload    = $payload;
        $this->opcode     = $opcode;
        $this->payloadLen = \strlen($payload);
    }

    public function maskPayload(?string $maskingKey = null): void
    {
        $this->assertNotPooled();
        $this->mask       = true;
        $this->maskingKey = $maskingKey ?? \random_bytes(4);
        $this->payload    = $this->applyMask($this->payload, $this->maskingKey);
    }

    public function unmaskPayload(): void
    {
        $this->assertNotPooled();
        if ($this->mask && null !== $this->maskingKey) {
            $this->payload    = $this->applyMask($this->payload, $this->maskingKey);
            $this->mask       = false;
            $this->maskingKey = null;
        }
    }

    private function applyMask(string $data, string $mask): string
    {
        $masked  = '';
        $dataLen = \strlen($data);
        for ($i = 0; $i < $dataLen; ++$i) {
            $masked .= $data[$i] ^ $mask[$i % 4];
        }

        return $masked;
    }

    /**
     * Encode the frame to a binary representation.
     */
    public function toString(): string
    {
        $this->assertNotPooled();
        $firstByte = ($this->fin ? 0x80 : 0x00) |
                     ($this->rsv1 ? 0x40 : 0x00) |
                     ($this->rsv2 ? 0x20 : 0x00) |
                     ($this->rsv3 ? 0x10 : 0x00) |
                     $this->opcode;

        $secondByte = $this->mask ? 0x80 : 0x00;

        if ($this->payloadLen < 126) {
            $secondByte |= $this->payloadLen;
            $header = \pack('CC', $firstByte, $secondByte);
        } elseif ($this->payloadLen < 65536) {
            $secondByte |= 126;
            $header = \pack('CCn', $firstByte, $secondByte, $this->payloadLen);
        } else {
            $secondByte |= 127;
            $header = \pack('CCJ', $firstByte, $secondByte, $this->payloadLen);
        }

        if ($this->mask) {
            $header .= $this->maskingKey;
        }

        return $header . $this->payload;
    }

    private function assertNotPooled(): void
    {
        if ($this->pooled) {
            throw new \LogicException("Can't use this instance after it was returned to the object pool");
        }
    }
}
