<?php

namespace phasync\Psr;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * A PSR-7 request as a server received it.
 *
 * Adds the server and cookie parameters, the query parameters, the uploaded files, the parsed body
 * and the attributes to {@see Request}. Like all PSR-7 messages it is immutable. The body, the
 * uploaded files and the parsed body can be given as closures, so that a server parses them only when
 * the application asks.
 *
 * ```php
 * $request = new ServerRequest('POST', '/login?next=/home', 'user=ann', ['Host' => 'example.com'], parsedBody: ['user' => 'ann']);
 * $request->getQueryParams();   // ['next' => '/home']
 * $request->getParsedBody();    // ['user' => 'ann']
 * ```
 *
 * @see Request
 * @see UploadedFile
 */
class ServerRequest extends Request implements ServerRequestInterface
{
    protected array $serverParams           = [];
    protected array $cookieParams           = [];
    protected ?array $queryParams           = null;
    protected array|\Closure $uploadedFiles = [];
    protected mixed $parsedBody             = null;
    protected array $attributes             = [];
    private ?\Closure $bodySource           = null;

    /**
     * Creates a server request.
     *
     * A Closure given for the body, the uploaded files or the parsed body is called each time that is asked for, until a `with...()` method replaces it: a server can parse the body on demand, and every clone sees the same state.
     *
     * @param string                          $method          case-sensitive HTTP method
     * @param string                          $requestTarget   request target, e.g. "/path?query=value"
     * @param mixed                           $body            body, see {@see StreamFactory::create()}, or a Closure returning it
     * @param array                           $headers         array of header names => values
     * @param ?array                          $queryParams     query params override; null derives them from the request target
     * @param array                           $serverParams    server params, like $_SERVER
     * @param array                           $cookieParams    cookie params, like $_COOKIE
     * @param UploadedFileInterface[]|\Closure $uploadedFiles   tree of uploaded file instances, or a Closure returning it
     * @param array|object|\Closure|null      $parsedBody      deserialized body data, or a Closure returning it
     * @param array                           $attributes      attributes derived from the request
     * @param string                          $protocolVersion the HTTP protocol version, typically "1.1" or "1.0"
     *
     * @throws \InvalidArgumentException when `$uploadedFiles` is not a tree of `UploadedFileInterface`
     */
    public function __construct(
        string $method,
        string $requestTarget,
        mixed $body,
        array $headers = [],
        ?array $queryParams = null,
        array $serverParams = [],
        array $cookieParams = [],
        array|\Closure $uploadedFiles = [],
        mixed $parsedBody = null,
        array $attributes = [],
        string $protocolVersion = '1.1',
    ) {
        if (\is_array($uploadedFiles) && !self::isValidUploadedFilesArray($uploadedFiles)) {
            self::throwInvalidUploadedFilesArray();
        }
        if ($body instanceof \Closure) {
            $this->bodySource = $body;
            $body             = null;
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

    /**
     * Clones a parsed body that is an object, so a copy does not share it.
     */
    public function __clone()
    {
        parent::__clone();
        if (\is_object($this->parsedBody) && !$this->parsedBody instanceof \Closure) {
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
        return $this->uploadedFiles instanceof \Closure ? ($this->uploadedFiles)() : $this->uploadedFiles;
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
        return $this->parsedBody instanceof \Closure ? ($this->parsedBody)() : $this->parsedBody;
    }

    public function getBody(): StreamInterface
    {
        return null !== $this->bodySource ? ($this->bodySource)() : parent::getBody();
    }

    public function withBody($body): MessageInterface
    {
        $c             = parent::withBody($body);
        $c->bodySource = null;

        return $c;
    }

    /** The cookies of a Cookie header, as PHP fills $_COOKIE: the first of equal names wins, values are URL-decoded. */
    public static function cookies(string $header): array
    {
        $cookies = [];
        foreach (\explode(';', $header) as $pair) {
            if (false !== ($eq = \strpos($pair, '='))) {
                $cookies[\trim(\substr($pair, 0, $eq), " \t")] ??= \urldecode(\trim(\substr($pair, $eq + 1), " \t"));
            }
        }

        return $cookies;
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
