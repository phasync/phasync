<?php

namespace phasync\Psr;

use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-7 response.
 *
 * Immutable, as PSR-7 requires: the `with...()` methods return a modified copy. The reason phrase
 * defaults to the standard one for the status code ({@see Response::PHRASES}). The body is made with
 * {@see StreamFactory::create()}.
 *
 * ```php
 * $response = new Response(404, ['Content-Type' => 'text/plain'], 'Not found');
 * $response->getStatusCode();       // 404
 * $response->getReasonPhrase();     // "Not Found"
 * $stream = new UnbufferedStream();
 * $response = $response->withBody($stream);
 * ```
 *
 * @see Request
 * @see UnbufferedStream
 *
 * @topic http-client
 *
 * @example examples/Response/1-build-a-response.php Build responses
 */
class Response implements ResponseInterface
{
    use MessageTrait;

    /**
     * Standard HTTP status code/reason phrases.
     *
     * @var array<int, string>
     */
    public const PHRASES = [
        100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing',
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information', 204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content', 207 => 'Multi-status', 208 => 'Already Reported',
        300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified', 305 => 'Use Proxy', 306 => 'Switch Proxy', 307 => 'Temporary Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required', 408 => 'Request Time-out', 409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Request Entity Too Large', 414 => 'Request-URI Too Large', 415 => 'Unsupported Media Type', 416 => 'Requested range not satisfiable', 417 => 'Expectation Failed', 418 => 'I\'m a teapot', 422 => 'Unprocessable Entity', 423 => 'Locked', 424 => 'Failed Dependency', 425 => 'Unordered Collection', 426 => 'Upgrade Required', 428 => 'Precondition Required', 429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large', 451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Time-out', 505 => 'HTTP Version not supported', 506 => 'Variant Also Negotiates', 507 => 'Insufficient Storage', 508 => 'Loop Detected', 511 => 'Network Authentication Required',
    ];

    protected int $statusCode      = 200;
    protected string $reasonPhrase = '';

    /**
     * Creates a response.
     *
     * @param int     $statusCode      HTTP status code
     * @param array   $headers         array of header names => values
     * @param mixed   $body            body, see {@see StreamFactory::create()}
     * @param string  $protocolVersion the HTTP protocol version, typically "1.1" or "1.0"
     * @param ?string $reasonPhrase    the HTTP reason phrase; null or '' gives the standard phrase for `$statusCode`, or '' when there is none
     *
     * @throws \InvalidArgumentException for a status code outside 100-599, a reason phrase with control characters, or an invalid header (a value that is not a string is rejected)
     *
     * @example examples/Response.__construct/1-invalid-arguments.php Invalid arguments throw
     */
    public function __construct(int $statusCode = 200, array $headers = [], mixed $body = null, string $protocolVersion = '1.1', ?string $reasonPhrase = null)
    {
        self::assertStatus($statusCode, $reasonPhrase);
        $this->statusCode   = $statusCode;
        $this->reasonPhrase = null !== $reasonPhrase && '' !== $reasonPhrase ? $reasonPhrase : (self::PHRASES[$statusCode] ?? '');
        $this->MessageTrait($body, $headers, $protocolVersion);
    }

    private static function assertStatus(mixed $code, mixed $reasonPhrase): void
    {
        if (!\is_int($code) || $code < 100 || $code > 599) {
            throw new \InvalidArgumentException('Invalid HTTP status code ' . (\is_scalar($code) ? \var_export($code, true) : '(' . \get_debug_type($code) . ')') . ': it must be an integer from 100 to 599');
        }
        if (null !== $reasonPhrase && (!\is_string($reasonPhrase) || \strlen($reasonPhrase) !== \strcspn($reasonPhrase, self::CONTROL_CHARS))) {
            throw new \InvalidArgumentException('Invalid reason phrase: it must be a string without control characters');
        }
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Returns a copy with the status `$code`, and `$reasonPhrase`, or the standard phrase for the code when it is ''.
     *
     * @throws \InvalidArgumentException for a status code that is not an integer from 100 to 599
     *
     * @example examples/Response.withStatus/1-reason-phrases.php Standard and custom reason phrases
     */
    public function withStatus($code, $reasonPhrase = ''): ResponseInterface
    {
        self::assertStatus($code, $reasonPhrase);
        $c               = clone $this;
        $c->statusCode   = $code;
        $c->reasonPhrase = '' !== $reasonPhrase ? $reasonPhrase : (self::PHRASES[$code] ?? '');

        return $c;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }
}
