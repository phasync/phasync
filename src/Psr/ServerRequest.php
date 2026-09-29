<?php

namespace phasync\Psr;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

class ServerRequest extends Request implements ServerRequestInterface
{
    protected array $serverParams  = [];
    protected array $cookieParams  = [];
    protected ?array $queryParams  = null;
    protected array $uploadedFiles = [];
    protected mixed $parsedBody    = null;
    protected array $attributes    = [];

    /**
     * @param string                  $method          case-sensitive HTTP method
     * @param string                  $requestTarget   request target, e.g. "/path?query=value"
     * @param mixed                   $body            body, see {@see StreamFactory::create()}
     * @param array                   $headers         array of header names => values
     * @param ?array                  $queryParams     query params override; null derives them from the request target
     * @param array                   $serverParams    server params, like $_SERVER
     * @param array                   $cookieParams    cookie params, like $_COOKIE
     * @param UploadedFileInterface[] $uploadedFiles   tree of uploaded file instances
     * @param array|object|null       $parsedBody      deserialized body data
     * @param array                   $attributes      attributes derived from the request
     * @param string                  $protocolVersion the HTTP protocol version, typically "1.1" or "1.0"
     */
    public function __construct(
        string $method,
        string $requestTarget,
        mixed $body,
        array $headers = [],
        ?array $queryParams = null,
        array $serverParams = [],
        array $cookieParams = [],
        array $uploadedFiles = [],
        mixed $parsedBody = null,
        array $attributes = [],
        string $protocolVersion = '1.1',
    ) {
        if (!self::isValidUploadedFilesArray($uploadedFiles)) {
            self::throwInvalidUploadedFilesArray();
        }
        parent::__construct($method, $requestTarget, $body, $headers, $protocolVersion);
        if (null !== $queryParams) {
            $this->queryParams = self::fixQueryParams($queryParams);
        }
        $this->serverParams  = $serverParams;
        $this->cookieParams  = $cookieParams;
        $this->uploadedFiles = $uploadedFiles;
        $this->parsedBody    = $parsedBody;
        $this->attributes    = $attributes;
    }

    public function __clone()
    {
        parent::__clone();
        if (\is_object($this->parsedBody)) {
            $this->parsedBody = clone $this->parsedBody;
        }
    }

    /**
     * Adds HTTPS detection from server params ('on' or '1') on top of
     * {@see Request::getUri()}.
     */
    public function getUri(): UriInterface
    {
        if (null !== $this->uriOverride) {
            return $this->uriOverride;
        }

        $uri = $this->requestTarget;
        if ('' !== ($host = $this->getHeaderLine('Host'))) {
            $https  = $this->serverParams['HTTPS'] ?? null;
            $scheme = ('on' === $https || '1' === $https) ? 'https' : 'http';
            $uri    = "{$scheme}://{$host}{$this->requestTarget}";
        }

        return new Uri($uri);
    }

    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    public function withCookieParams($cookies): ServerRequestInterface
    {
        $c               = clone $this;
        $c->cookieParams = $cookies;

        return $c;
    }

    public function getQueryParams(): array
    {
        if (null !== $this->queryParams) {
            return $this->queryParams;
        }
        $query = $this->getQuery();
        if ('' === $query) {
            return [];
        }
        \parse_str($query, $params);

        return $params;
    }

    public function withQueryParams($query): ServerRequestInterface
    {
        $c              = clone $this;
        $c->queryParams = $query;

        return $c;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    public function withUploadedFiles($uploadedFiles): ServerRequestInterface
    {
        if (!self::isValidUploadedFilesArray($uploadedFiles)) {
            self::throwInvalidUploadedFilesArray();
        }
        $c                = clone $this;
        $c->uploadedFiles = $uploadedFiles;

        return $c;
    }

    public function getParsedBody()
    {
        return $this->parsedBody;
    }

    public function withParsedBody($data): ServerRequestInterface
    {
        if (null !== $data && !\is_array($data) && !\is_object($data)) {
            throw new \InvalidArgumentException('Expecting array, object or NULL');
        }
        $c             = clone $this;
        $c->parsedBody = $data;

        return $c;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute($name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute($name, $value): ServerRequestInterface
    {
        $c                    = clone $this;
        $c->attributes[$name] = $value;

        return $c;
    }

    public function withoutAttribute($name): ServerRequestInterface
    {
        $c = clone $this;
        unset($c->attributes[$name]);

        return $c;
    }

    /**
     * Validates a tree of uploaded files: a leaf must be an
     * UploadedFileInterface, but a branch may itself be an array, to
     * support HTML field names like "files[]" or "files[avatar]".
     */
    protected static function isValidUploadedFilesArray(array $uploadedFiles): bool
    {
        foreach ($uploadedFiles as $uploadedFile) {
            if ($uploadedFile instanceof UploadedFileInterface) {
                continue;
            }
            if (\is_array($uploadedFile) && self::isValidUploadedFilesArray($uploadedFile)) {
                continue;
            }

            return false;
        }

        return true;
    }

    protected static function throwInvalidUploadedFilesArray(): void
    {
        throw new \InvalidArgumentException("Expecting a tree of '" . UploadedFileInterface::class . "' instances");
    }

    /**
     * Ensures that the passed query params adhere to the shape of query
     * params as they would come from $_GET.
     */
    protected static function fixQueryParams(array $queryParams): array
    {
        $builtString = \http_build_query($queryParams);
        \parse_str($builtString, $parsedQueryParams);

        return $parsedQueryParams;
    }
}
