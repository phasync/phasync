<?php

namespace phasync\Psr;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

class Request implements RequestInterface
{
    use MessageTrait;

    private ?string $requestTarget = null;
    private string $method;
    private UriInterface $uri;

    public function __construct(string $method, string|UriInterface $uri, array $headers=[], mixed $body=null, string $protocolVersion='1.1')
    {
        $this->method = $method;
        $this->uri    = $uri instanceof UriInterface ? $uri : new Uri($uri);
        $this->MessageTrait($body, $headers, $protocolVersion);
    }

    /**
     * Retrieves the message's request target.
     *
     * Retrieves the message's request-target either as it will appear (for
     * clients), as it appeared at request (for servers), or as it was
     * specified for the instance (see withRequestTarget()).
     *
     * In most cases, this will be the origin-form of the composed URI,
     * unless a value was provided to the concrete implementation (see
     * withRequestTarget() below).
     *
     * If no URI is available, and no request-target has been specifically
     * provided, this method MUST return the string "/".
     */
    public function getRequestTarget(): string
    {
        if (null === $this->requestTarget) {
            $target = $this->uri->getPath();
            if ('' === $target) {
                return '/';
            }

            $query = $this->uri->getQuery();
            if ('' !== $query) {
                return $target . '?' . $query;
            }

            return $target;
        }

        return $this->requestTarget;
    }

    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        $c                = clone $this;
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

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        $c = clone $this;
        if ($preserveHost) {
            $c->uri = $uri->withHost($this->uri->getHost());
        } else {
            $c->uri = $uri;
        }

        return $c;
    }
}
