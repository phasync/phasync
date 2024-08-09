<?php

namespace phasync\Util\Protocols;

use phasync\Internal\ObjectPoolInterface;
use phasync\Internal\ObjectPoolTrait;
use phasync\Util\StringBuffer;

/**
 * This class provides a implementation for parsing and creating WebSocket frames
 * according to the WebSocket protocol specification (RFC 6455).
 *
 * To parse a WebSocket frame from a StringBuffer:
 *
 * ```
 * $frame = WebSocketFrame::parse($stringBuffer);
 * if ($frame !== null) {
 *   $payload = $frame->getPayload(); // Gets the frame payload
 *   $opcode = $frame->getOpcode(); // Gets the frame opcode
 * }
 * ```
 *
 * To create a WebSocket frame:
 *
 * ```
 * $frame = WebSocketFrame::popInstance() ?? new WebSocketFrame();
 * $frame->setPayload("Hello, WebSocket!");
 * $frame->setFin(true);
 * $frame->setOpcode(WebSocketFrame::TEXT_FRAME);
 * $frameData = $frame->toString(); // Binary WebSocket frame as a string
 * ```
 */
class WebSocketFrame implements ObjectPoolInterface
{
    use ObjectPoolTrait;

    // OpCode constants
    public const CONTINUATION_FRAME = 0x0;
    public const TEXT_FRAME         = 0x1;
    public const BINARY_FRAME       = 0x2;
    public const CONNECTION_CLOSE   = 0x8;
    public const PING               = 0x9;
    public const PONG               = 0xA;

    public int $opcode     = 0;
    public string $payload = '';

    public function returnToPool(): void
    {
        $this->payload = '';
        $this->pushInstance();
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function isPing(): bool
    {
        return $this->opcode & self::PING;
    }

    public function isPong(): bool
    {
        return $this->opcode & self::PONG;
    }

    public function isText(): bool
    {
        return $this->opcode & self::TEXT_FRAME;
    }

    public function isBinary(): bool
    {
        return $this->opcode & self::BINARY_FRAME;
    }

    public function setPing(string $payload = ''): void
    {
        $this->opcode  = self::PING;
        $this->payload = $payload;
    }

    public function setPong(string $payload = ''): void
    {
        $this->opcode  = self::PONG;
        $this->payload = $payload;
    }

    public function setClose(int $code = 1000, string $reason = ''): void
    {
        $this->opcode  = self::CONNECTION_CLOSE;
        $this->payload = \pack('na*', $code, $reason);
    }

    public function setBinary(string $content): void
    {
        $this->opcode  = self::BINARY_FRAME;
        $this->payload = $content;
    }

    public function setText(string $content): void
    {
        $this->opcode  = self::TEXT_FRAME;
        $this->payload = $content;
    }

    public static function parse(StringBuffer $buffer): ?static
    {
        $data = $buffer->readFixed(2);
        if (null === $data) {
            return null;
        }

        $instance = self::popInstance() ?? new static();

        $firstByte  = \ord($data[0]);
        $secondByte = \ord($data[1]);

        $instance->opcode = $firstByte & 0x0F;
        $masked           = ($secondByte & 0x80) !== 0;
        $payloadLength    = $secondByte & 0x7F;

        if (126 === $payloadLength) {
            $extendedPayloadLength = $buffer->readFixed(2);
            if (null === $extendedPayloadLength) {
                $buffer->unread($data);

                return null;
            }
            $payloadLength = \unpack('n', $extendedPayloadLength)[1];
        } elseif (127 === $payloadLength) {
            $extendedPayloadLength = $buffer->readFixed(8);
            if (null === $extendedPayloadLength) {
                $buffer->unread($data);

                return null;
            }
            $payloadLength = \unpack('J', $extendedPayloadLength)[1];
        }

        $maskingKey = '';
        if ($masked) {
            $maskingKey = $buffer->readFixed(4);
            if (null === $maskingKey) {
                $buffer->unread($data);

                return null;
            }
        }

        $instance->payload = $buffer->readFixed($payloadLength);
        if (null === $instance->payload) {
            $buffer->unread($data);

            return null;
        }

        if ($masked) {
            $instance->payload = self::applyMask($instance->payload, $maskingKey);
        }

        return $instance;
    }

    public function toString(bool $masked = false): string
    {
        $length     = \strlen($this->payload);
        $firstByte  = 0x80 | $this->opcode;
        $secondByte = $masked ? 0x80 : 0x00;

        if ($length < 126) {
            $header = \pack('CC', $firstByte, $secondByte | $length);
        } elseif ($length < 65536) {
            $header = \pack('CCn', $firstByte, $secondByte | 126, $length);
        } else {
            $header = \pack('CCJ', $firstByte, $secondByte | 127, $length);
        }

        if ($masked) {
            $maskingKey = \random_bytes(4);

            return $header . $maskingKey . self::applyMask($this->payload, $maskingKey);
        }

        return $header . $this->payload;
    }

    private static function applyMask(string $data, string $mask): string
    {
        $masked  = '';
        $maskLen = \strlen($mask);
        for ($i = 0; $i < \strlen($data); ++$i) {
            $masked .= $data[$i] ^ $mask[$i % $maskLen];
        }

        return $masked;
    }
}
