<?php

namespace phasync\Util\Protocols;

use phasync\Internal\ObjectPoolInterface;
use phasync\Internal\ObjectPoolTrait;
use phasync\Util\StringBuffer;

/**
 * This class provides a fast and simple binary protocol for length prefixed
 * chunked streaming with the StringBuffer class. Given a StringBuffer that
 * contains a binary protocol of length prefixed unsigned longs (32 bit big
 * endian order/network byte order) this protocol will generate and parse a
 * binary stream.
 *
 * To parse a StringBuffer stream:
 *
 * ```
 * $frame = LengthPrefixedFraming::parse($stringBuffer);
 * if ($frame !== null) {
 *   $data = $frame->getPayload(); // Gets the binary payload
 * }
 * ```
 *
 * To write a binary chunk:
 *
 * ```
 * $frame->setPayload("Some binary data");
 * $chunk = $frame->toString(); // Binary prefixed chunk as a string
 * ```
 */
class LengthPrefixedFraming implements ObjectPoolInterface
{
    use ObjectPoolTrait;

    /**
     * Contains the full binary data.
     */
    private string $payload;

    /**
     * Return this instance back to the object pool so that it can
     * be reused. Be absolutely certain that the object is not
     * referenced elsewhere.
     */
    public function returnToPool(): void
    {
        $this->payload = '';
        $this->pushInstance();
    }

    public static function parse(StringBuffer $buffer): ?static
    {
        $lengthBytes = $buffer->readFixed(4);
        if (null === $lengthBytes) {
            return null;
        }
        $length  = \unpack('N', $lengthBytes);
        $payload = $buffer->readFixed($length);
        if (null === $payload) {
            $buffer->unread($lengthBytes);
        }
        $instance          = self::popInstance() ?? new static();
        $instance->payload = $payload;

        return $instance;
    }

    /**
     * Sets the framed payload.
     */
    final public function setPayload(string $payload): void
    {
        $this->payload = $payload;
    }

    /**
     * Get the payload body without length.
     */
    final public function getPayload(): string
    {
        return $this->payload;
    }

    public function toString(): string
    {
        return \pack('Na*', \strlen($this->payload), $this->payload);
    }
}
