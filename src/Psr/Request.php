<?php

namespace phasync\Psr;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Representation of an outgoing, client-side request.
 *
 * The request-target and the URI are independent, per PSR-7: the
 * request-target is the source of truth (as it arrived, or as set via
 * {@see self::withRequestTarget()}), and {@see self::getUri()} derives a
 * URI from it plus the Host header unless a URI was explicitly attached via
 * {@see self::withUri()}.
 */
class Request implements RequestInterface
{
    use MessageTrait;

    protected string $method;
    protected string $requestTarget;
    protected ?UriInterface $uriOverride = null;

    /**
     * @param string $method          case-sensitive HTTP method
     * @param string $requestTarget   request target, e.g. "/path?query=value"
     * @param mixed  $body            body, see {@see StreamFactory::create()}
     * @param array  $headers         array of header names => values
     * @param string $protocolVersion the HTTP protocol version, typically "1.1" or "1.0"
     */
    public function __construct(string $method, string $requestTarget, mixed $body = '', array $headers = [], string $protocolVersion = '1.1')
    {
        $this->method        = $method;
        $this->requestTarget = '' !== $requestTarget ? $requestTarget : '/';
        $this->MessageTrait($body, $headers, $protocolVersion);
    }

    public function __clone()
    {
        if (null !== $this->uriOverride) {
            $this->uriOverride = clone $this->uriOverride;
        }
        if (\is_object($this->body)) {
            $this->body = clone $this->body;
        }
    }

    /**
     * Create a request from a URI, deriving the request-target and Host
     * header from it. This is the shape used by {@see PsrFactory::createRequest()}.
     *
     * @param string|\Stringable|UriInterface $uri full or relative URI
     */
    public static function create(string $method, string|\Stringable|UriInterface $uri): RequestInterface
    {
        if (!$uri instanceof UriInterface) {
            $uri = new Uri((string) $uri);
        }

        $requestTarget = $uri->getPath() ?: '/';
        if ('' !== ($query = $uri->getQuery())) {
            $requestTarget .= '?' . $query;
        }

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
     * Retrieves the message's request target. The request target is stored
     * directly and returned as-is; it does not get constructed from the URI.
     */
    public function getRequestTarget(): string
    {
        return $this->requestTarget;
    }

    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        $c = clone $this;
        // Freeze the URI before changing the request target: PSR-7 treats the URI
        // and the request-target as independent once either is set explicitly.
        if (null === $c->uriOverride) {
            $c->uriOverride = $this->getUri();
        }
        $c->requestTarget = $requestTarget;

        return $c;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): RequestInterface
    {
        $c         = clone $this;
        $c->method = $method;

        return $c;
    }

    /**
     * Returns the attached URI override, or a URI derived from the
     * request-target plus the Host header.
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

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        $host = $uri->getHost();
        if (($preserveHost && $this->hasHeader('Host')) || '' === $host) {
            $c = clone $this;
        } else {
            $port = $uri->getPort();
            $c    = $this->withHeader('Host', $host . (null !== $port ? ':' . $port : ''));
        }
        $c->uriOverride = clone $uri;

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
