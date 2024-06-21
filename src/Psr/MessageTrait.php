<?php

namespace phasync\Psr;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * HTTP messages consist of requests from a client to a server and responses
 * from a server to a client. This interface defines the methods common to
 * each.
 *
 * Messages are considered immutable; all methods that might change state MUST
 * be implemented such that they retain the internal state of the current
 * message and return an instance that contains the changed state.
 *
 * @see http://www.ietf.org/rfc/rfc7230.txt
 * @see http://www.ietf.org/rfc/rfc7231.txt
 */
trait MessageTrait
{
    protected string $protocolVersion;
    protected StreamInterface $body;
    protected array $headers     = [];
    protected array $headerCases = [];

    public function __clone()
    {
        if (\is_object($this->body)) {
            $this->body = clone $this->body;
        }
    }

    /**
     * Configure the message trait.
     *
     * @param string|object|array|StreamInterface $body            Body
     * @param string[][]                          $headers         Array of header names => values
     * @param string                              $protocolVersion The HTTP protocol version, typically "1.1" or "1.0"
     */
    protected function MessageTrait(mixed $body, array $headers=[], string $protocolVersion='1.1')
    {
        $this->body            = $body;
        $this->protocolVersion = $protocolVersion;
        foreach ($headers as $name => $values) {
            $key                     = \strtolower($name);
            $this->headerCases[$key] = $name;
            if (\is_string($values)) {
                $this->headers[$key] = [$values];
            } else {
                $this->headers[$key] = [...$values];
            }
        }
    }

    /**
     * Retrieves the HTTP protocol version as a string.
     *
     * The string MUST contain only the HTTP version number (e.g., "1.1", "1.0").
     *
     * @return string HTTP protocol version
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * Return an instance with the specified HTTP protocol version.
     *
     * The version string MUST contain only the HTTP version number (e.g.,
     * "1.1", "1.0").
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * new protocol version.
     *
     * @param string $version HTTP protocol version
     *
     * @return static
     */
    public function withProtocolVersion($version): RequestInterface
    {
        $c                  = clone $this;
        $c->protocolVersion = (string) $version;

        return $c;
    }

    /**
     * Get message headers
     *
     * @return array<string, string[]>
     */
    public function getHeaders(): array
    {
        $result = [];
        foreach ($this->headers as $key => $values) {
            $result[$this->headerCases[$key]] = $values;
        }

        return $result;
    }

    /**
     * Checks if a header exists by the given case-insensitive name.
     *
     * @param string $name case-insensitive header field name
     *
     * @return bool Returns true if any header names match the given header
     *              name using a case-insensitive string comparison. Returns false if
     *              no matching header name is found in the message.
     */
    public function hasHeader($name): bool
    {
        return !empty($this->headers[\strtolower($name)]);
    }

    /**
     * Retrieves a message header value by the given case-insensitive name.
     *
     * This method returns an array of all the header values of the given
     * case-insensitive header name.
     *
     * If the header does not appear in the message, this method MUST return an
     * empty array.
     *
     * @param string $name case-insensitive header field name
     *
     * @return string[] An array of string values as provided for the given
     *                  header. If the header does not appear in the message, this method MUST
     *                  return an empty array.
     */
    public function getHeader($name): array
    {
        return $this->headers[\strtolower($name)] ?? [];
    }

    /**
     * Retrieves a comma-separated string of the values for a single header.
     *
     * This method returns all of the header values of the given
     * case-insensitive header name as a string concatenated together using
     * a comma.
     *
     * NOTE: Not all header values may be appropriately represented using
     * comma concatenation. For such headers, use getHeader() instead
     * and supply your own delimiter when concatenating.
     *
     * If the header does not appear in the message, this method MUST return
     * an empty string.
     *
     * @param string $name case-insensitive header field name
     *
     * @return string A string of values as provided for the given header
     *                concatenated together using a comma. If the header does not appear in
     *                the message, this method MUST return an empty string.
     */
    public function getHeaderLine($name): string
    {
        return \implode(', ', $this->getHeader($name));
    }

    /**
     * Return an instance with the provided value replacing the specified header.
     *
     * While header names are case-insensitive, the casing of the header will
     * be preserved by this function, and returned from getHeaders().
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * new and/or updated header and value.
     *
     * @param string          $name  case-insensitive header field name
     * @param string|string[] $value header value(s)
     *
     * @throws \InvalidArgumentException for invalid header names or values
     *
     * @return static
     */
    public function withHeader($name, $value): MessageInterface
    {
        $c   = clone $this;
        $key = \strtolower($name);
        if (!\is_array($value)) {
            $c->headers[$key] = [(string) $value];
        } else {
            $c->headers[$key] = [...$value];
        }
        $c->headerCases[$key] = $name;

        return $c;
    }

    /**
     * Return an instance with the specified header appended with the given value.
     *
     * Existing values for the specified header will be maintained. The new
     * value(s) will be appended to the existing list. If the header did not
     * exist previously, it will be added.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * new header and/or value.
     *
     * @param string          $name  case-insensitive header field name to add
     * @param string|string[] $value header value(s)
     *
     * @throws \InvalidArgumentException for invalid header names
     * @throws \InvalidArgumentException for invalid header values
     *
     * @return static
     */
    public function withAddedHeader($name, $value): MessageInterface
    {
        $c   = clone $this;
        $key = \strtolower($name);
        if (\is_string($value)) {
            $c->headers[$key][] = $value;
        } else {
            \array_push($c->headers[$key], ...$value);
        }
        $c->headerCases[$key] = $name;

        return $c;
    }

    /**
     * Return an instance without the specified header.
     *
     * Header resolution MUST be done without case-sensitivity.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that removes
     * the named header.
     *
     * @param string $name case-insensitive header field name to remove
     *
     * @return static
     */
    public function withoutHeader($name): MessageInterface
    {
        $c   = clone $this;
        $key = \strtolower($name);
        unset($c->headers[$key], $c->headerCases[$key]);

        return $c;
    }

    /**
     * Gets the body of the message.
     *
     * @return StreamInterface returns the body as a stream
     */
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    /**
     * Return an instance with the specified message body.
     *
     * The body MUST be a StreamInterface object.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return a new instance that has the
     * new body stream.
     *
     * @param StreamInterface $body body
     *
     * @throws \InvalidArgumentException when the body is not valid
     *
     * @return static
     */
    public function withBody(StreamInterface $body): MessageInterface
    {
        $c       = clone $this;
        $c->body = $body;

        return $c;
    }
}
