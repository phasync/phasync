<?php

namespace phasync\Psr;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

class ServerRequest extends Request implements ServerRequestInterface
{
    protected array $serverParams  = [];
    protected array $cookieParams  = [];
    protected array $queryParams   = [];
    protected array $uploadedFiles = [];
    protected mixed $parsedBody    = null;
    protected array $attributes    = [];

    /**
     * @param string              $method       the HTTP method associated with the request
     * @param UriInterface|string $uri          the URI associated with the request
     * @param array               $serverParams an array of Server API (SAPI) parameters with
     *                                          which to seed the generated request instance
     */
    public function __construct(string $method, $uri, array $serverParams = [])
    {
        parent::__construct($method, $uri);
        $this->serverParams = $serverParams;
    }

    /**
     * Retrieve server parameters.
     *
     * Retrieves data related to the incoming request environment,
     * typically derived from PHP's $_SERVER superglobal. The data IS NOT
     * REQUIRED to originate from $_SERVER.
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * Retrieve cookies.
     *
     * Retrieves cookies sent by the client to the server.
     *
     * The data MUST be compatible with the structure of the $_COOKIE
     * superglobal.
     */
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    /**
     * Return an instance with the specified cookies.
     *
     * The data IS NOT REQUIRED to come from the $_COOKIE superglobal, but MUST
     * be compatible with the structure of $_COOKIE. Typically, this data will
     * be injected at instantiation.
     *
     * This method MUST NOT update the related Cookie header of the request
     * instance, nor related values in the server params.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * updated cookie values.
     *
     * @param array $cookies array of key/value pairs representing cookies
     *
     * @return static
     */
    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $c               = clone $this;
        $c->cookieParams = $cookies;

        return $c;
    }

    /**
     * Retrieve query string arguments.
     *
     * Retrieves the deserialized query string arguments, if any.
     *
     * Note: the query params might not be in sync with the URI or server
     * params. If you need to ensure you are only getting the original
     * values, you may need to parse the query string from `getUri()->getQuery()`
     * or from the `QUERY_STRING` server param.
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * Return an instance with the specified query string arguments.
     *
     * These values SHOULD remain immutable over the course of the incoming
     * request. They MAY be injected during instantiation, such as from PHP's
     * $_GET superglobal, or MAY be derived from some other value such as the
     * URI. In cases where the arguments are parsed from the URI, the data
     * MUST be compatible with what PHP's parse_str() would return for
     * purposes of how duplicate query parameters are handled, and how nested
     * sets are handled.
     *
     * Setting query string arguments MUST NOT change the URI stored by the
     * request, nor the values in the server params.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * updated query string arguments.
     *
     * @param array $query array of query string arguments, typically from
     *                     $_GET
     *
     * @return static
     */
    public function withQueryParams(array $query): ServerRequestInterface
    {
        $c              = clone $this;
        $c->queryParams = $query;

        return $c;
    }

    /**
     * Retrieve normalized file upload data.
     *
     * This method returns upload metadata in a normalized tree, with each leaf
     * an instance of Psr\Http\Message\UploadedFileInterface.
     *
     * These values MAY be prepared from $_FILES or the message body during
     * instantiation, or MAY be injected via withUploadedFiles().
     *
     * @return array an array tree of UploadedFileInterface instances; an empty
     *               array MUST be returned if no data is present
     */
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * Create a new instance with the specified uploaded files.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * updated body parameters.
     *
     * @param array $uploadedFiles an array tree of UploadedFileInterface instances
     *
     * @throws \InvalidArgumentException if an invalid structure is provided
     *
     * @return static
     */
    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        if (!self::isValidUploadedFilesArray($uploadedFiles)) {
            self::throwInvalidUploadedFilesArray();
        }
        $c                = clone $this;
        $c->uploadedFiles = $uploadedFiles;

        return $c;
    }

    /**
     * Retrieve any parameters provided in the request body.
     *
     * If the request Content-Type is either application/x-www-form-urlencoded
     * or multipart/form-data, and the request method is POST, this method MUST
     * return the contents of $_POST.
     *
     * Otherwise, this method may return any results of deserializing
     * the request body content; as parsing returns structured content, the
     * potential types MUST be arrays or objects only. A null value indicates
     * the absence of body content.
     *
     * @return array|object|null The deserialized body parameters, if any.
     *                           These will typically be an array or object.
     */
    public function getParsedBody()
    {
        return $this->parsedBody;
    }

    /**
     * Return an instance with the specified body parameters.
     *
     * These MAY be injected during instantiation.
     *
     * If the request Content-Type is either application/x-www-form-urlencoded
     * or multipart/form-data, and the request method is POST, use this method
     * ONLY to inject the contents of $_POST.
     *
     * The data IS NOT REQUIRED to come from $_POST, but MUST be the results of
     * deserializing the request body content. Deserialization/parsing returns
     * structured data, and, as such, this method ONLY accepts arrays or objects,
     * or a null value if nothing was available to parse.
     *
     * As an example, if content negotiation determines that the request data
     * is a JSON payload, this method could be used to create a request
     * instance with the deserialized parameters.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * updated body parameters.
     *
     * @param array|object|null $data The deserialized body data. This will
     *                                typically be in an array or object.
     *
     * @throws \InvalidArgumentException if an unsupported argument type is
     *                                   provided
     *
     * @return static
     */
    public function withParsedBody($data): ServerRequestInterface
    {
        if (null !== $data && !\is_array($data) && !\is_object($data)) {
            throw new \InvalidArgumentException('Expecting array, object or NULL');
        }
        $c             = clone $this;
        $c->parsedBody = $data;

        return $c;
    }

    /**
     * Retrieve attributes derived from the request.
     *
     * The request "attributes" may be used to allow injection of any
     * parameters derived from the request: e.g., the results of path
     * match operations; the results of decrypting cookies; the results of
     * deserializing non-form-encoded message bodies; etc. Attributes
     * will be application and request specific, and CAN be mutable.
     *
     * @return mixed[] attributes derived from the request
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Retrieve a single derived request attribute.
     *
     * Retrieves a single derived request attribute as described in
     * getAttributes(). If the attribute has not been previously set, returns
     * the default value as provided.
     *
     * This method obviates the need for a hasAttribute() method, as it allows
     * specifying a default value to return if the attribute is not found.
     *
     * @see getAttributes()
     *
     * @param string $name    the attribute name
     * @param mixed  $default default value to return if the attribute does not exist
     */
    public function getAttribute($name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * Return an instance with the specified derived request attribute.
     *
     * This method allows setting a single derived request attribute as
     * described in getAttributes().
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * updated attribute.
     *
     * @see getAttributes()
     *
     * @param string $name  the attribute name
     * @param mixed  $value the value of the attribute
     *
     * @return static
     */
    public function withAttribute($name, $value): ServerRequestInterface
    {
        $c                    = clone $this;
        $c->attributes[$name] = $value;

        return $c;
    }

    /**
     * Return an instance that removes the specified derived request attribute.
     *
     * This method allows removing a single derived request attribute as
     * described in getAttributes().
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that removes
     * the attribute.
     *
     * @see getAttributes()
     *
     * @param string $name the attribute name
     *
     * @return static
     */
    public function withoutAttribute($name): ServerRequestInterface
    {
        $c = clone $this;
        unset($c->attributes[$name]);

        return $c;
    }

    /**
     * Validate an array as per the {@see self::withUploadedFiles()} function.
     */
    protected static function isValidUploadedFilesArray(array $uploadedFiles): bool
    {
        foreach ($uploadedFiles as $uploadedFile) {
            if (!($uploadedFile instanceof UploadedFileInterface)) {
                return false;
            }
        }

        return true;
    }

    protected static function throwInvalidUploadedFilesArray(): void
    {
        throw new \InvalidArgumentException("Expecting an array of '" . UploadedFileInterface::class . "' instances");
    }

    /**
     * Ensures that the passed query params adhere to the shape of
     * query params as they would come from $_GET.
     */
    protected static function fixQueryParams(array $queryParams): array
    {
        $builtString = \http_build_query($queryParams);
        \parse_str($builtString, $parsedQueryParams);

        return $parsedQueryParams;
    }
}
