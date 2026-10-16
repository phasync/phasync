<?php

namespace phasync\Psr;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * A PSR-7 outgoing, client-side request.
 *
 * The request-target and the URI are independent, as PSR-7 has them: the request-target is the
 * source of truth, and {@see Request::getUri()} derives a URI from it and the Host header, unless one
 * was set with {@see Request::withUri()}.
 *
 * ```php
 * $request = new Request('POST', '/search?q=phasync', '{"limit":10}', [
 *     'Host'         => 'example.com',
 *     'Content-Type' => 'application/json',
 * ]);
 * (string) $request->getUri();   // "http://example.com/search?q=phasync"
 * ```
 *
 * @see ServerRequest
 * @see Response
 *
 * @topic http-client
 *
 * @example examples/Request/1-build-a-request.php Build a request
 */
class Request implements RequestInterface
{
    use MessageTrait;

    protected string $method;
    protected string $requestTarget;
    protected ?UriInterface $uriOverride = null;

    /** True once the request target was set with withRequestTarget(): withUri() then leaves it alone. */
    protected bool $requestTargetPinned = false;

    /**
     * Creates a request.
     *
     * @param string $method          case-sensitive HTTP method
     * @param string $requestTarget   request target, e.g. "/path?query=value"; '' becomes "/"
     * @param mixed  $body            body, see {@see StreamFactory::create()}
     * @param array  $headers         array of header names => values
     * @param string $protocolVersion the HTTP protocol version, typically "1.1" or "1.0"
     *
     * @throws \InvalidArgumentException for a method that is not an RFC 7230 token, a request target containing whitespace or control characters, or an invalid header (a value that is not a string is rejected)
     */
    public function __construct(string $method, string $requestTarget, mixed $body = '', array $headers = [], string $protocolVersion = '1.1')
    {
        self::assertMethod($method);
        self::assertRequestTarget($requestTarget);
        $this->method        = $method;
        $this->requestTarget = '' !== $requestTarget ? $requestTarget : '/';
        $this->MessageTrait($body, $headers, $protocolVersion);
    }

    /**
     * Clones the URI set with `withUri()`, so a copy does not share it.
     */
    public function __clone()
    {
        if (null !== $this->uriOverride) {
            $this->uriOverride = clone $this->uriOverride;
        }
    }

    /**
     * Creates a request from a URI, deriving the request-target and Host header from it.
     *
     * This is the shape used by the PSR-17 request factory.
     *
     * @param string|\Stringable|UriInterface $uri full or relative URI
     *
     * @example examples/Request.create/1-from-a-url.php A request from a URL
     */
    public static function create(string $method, string|\Stringable|UriInterface $uri): RequestInterface
    {
        if (!$uri instanceof UriInterface) {
            $uri = new Uri((string) $uri);
        }

        $requestTarget = self::originForm($uri);

        $headers = [];
        if ('' !== ($host = $uri->getHost())) {
            $headers['Host'] = $host . (null !== $uri->getPort() ? ':' . $uri->getPort() : '');
        }

        $request = new static($method, $requestTarget, '', $headers);

        if ('' !== $uri->getScheme() || '' !== $host) {
            $request = $request->withUri($uri);
        }

        return $request;
    }

    /**
     * The origin-form request target (RFC 7230 section 5.3.1) of a URI: its path, "/" when empty, and its query.
     */
    private static function originForm(UriInterface $uri): string
    {
        $target = $uri->getPath();
        if ('' === $target) {
            $target = '/';
        }
        if ('' !== ($query = $uri->getQuery())) {
            $target .= '?' . $query;
        }

        return $target;
    }

    private static function assertMethod(mixed $method): void
    {
        if (!\is_string($method) || '' === $method || \strlen($method) !== \strspn($method, self::TOKEN_CHARS)) {
            throw new \InvalidArgumentException('Invalid HTTP method ' . (\is_string($method) ? \var_export($method, true) : '(' . \get_debug_type($method) . ')') . ': it must be a non-empty string of RFC 7230 token characters, e.g. "GET"');
        }
    }

    private static function assertRequestTarget(mixed $requestTarget): void
    {
        if (!\is_string($requestTarget) || \strlen($requestTarget) !== \strcspn($requestTarget, " \t\r\n\v\f" . self::CONTROL_CHARS)) {
            throw new \InvalidArgumentException('Invalid request target: it must be a string without whitespace or control characters');
        }
    }

    /**
     * Returns the request target of the message.
     *
     * Set with the constructor or {@see Request::withRequestTarget()}, it is returned as it is. {@see Request::withUri()}
     * replaces it with the origin-form of the URI (path and query), unless it was set with `withRequestTarget()`.
     */
    public function getRequestTarget(): string
    {
        return $this->requestTarget;
    }

    /**
     * @throws \InvalidArgumentException when the target contains whitespace or control characters
     */
    public function withRequestTarget($requestTarget): RequestInterface
    {
        self::assertRequestTarget($requestTarget);
        $c = clone $this;
        // Freeze the URI before changing the request target: PSR-7 treats the URI
        // and the request-target as independent once either is set explicitly.
        if (null === $c->uriOverride) {
            $c->uriOverride = $this->getUri();
        }
        $c->requestTarget       = '' !== $requestTarget ? $requestTarget : '/';
        $c->requestTargetPinned = true;

        return $c;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @throws \InvalidArgumentException when the method is not an RFC 7230 token
     */
    public function withMethod($method): RequestInterface
    {
        self::assertMethod($method);
        $c         = clone $this;
        $c->method = $method;

        return $c;
    }

    /**
     * Returns the attached URI override, or a URI derived from the
     * request-target plus the Host header.
     *
     * @example examples/Request.getUri/1-target-and-uri.php The request target, the URI and the Host header
     */
    public function getUri(): UriInterface
    {
        if (null !== $this->uriOverride) {
            return $this->uriOverride;
        }

        $uri = $this->requestTarget;
        if ('' !== ($host = $this->getHeaderLine('Host'))) {
            $uri = "http://{$host}{$this->requestTarget}";
        }

        return new Uri($uri);
    }

    public function withUri($uri, $preserveHost = false): RequestInterface
    {
        $host = $uri->getHost();
        if (($preserveHost && $this->hasHeader('Host')) || '' === $host) {
            $c = clone $this;
        } else {
            $port = $uri->getPort();
            $c    = $this->withHeader('Host', $host . (null !== $port ? ':' . $port : ''));
        }
        $c->uriOverride = clone $uri;
        if (!$c->requestTargetPinned) {
            $c->requestTarget = self::originForm($uri);
        }

        return $c;
    }

    /**
     * The query string from the request target, or from the URI override
     * when one is attached.
     */
    public function getQuery(): string
    {
        if (null !== $this->uriOverride) {
            return $this->uriOverride->getQuery();
        }
        $target = $this->requestTarget;
        if (\str_contains($target, '?')) {
            return \substr($target, \strpos($target, '?') + 1);
        }

        return '';
    }
}
