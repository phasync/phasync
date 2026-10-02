<?php

namespace phasync\Psr;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-17 factories for the classes of phasync\Psr, in one object.
 *
 * @internal not part of the public API; may change in any release
 */
class PsrFactory implements UploadedFileFactoryInterface, ServerRequestFactoryInterface, ResponseFactoryInterface, RequestFactoryInterface, StreamFactoryInterface, UriFactoryInterface
{
    public function createUploadedFile(StreamInterface $stream, ?int $size = null, int $error = \UPLOAD_ERR_OK, ?string $clientFilename = null, ?string $clientMediaType = null): UploadedFileInterface
    {
        return new UploadedFile($stream, $clientFilename, $clientMediaType, $size, $error);
    }

    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
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

        $request = new ServerRequest($method, $requestTarget, '', $headers, null, $serverParams);

        if ('' !== $uri->getScheme() || '' !== $host) {
            $request = $request->withUri($uri);
        }

        return $request;
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($code, [], null, '1.1', $reasonPhrase);
    }

    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return new StringStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        $fp = \fopen($filename, $mode);

        return $this->createStreamFromResource($fp);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        \stream_set_blocking($resource, false);

        return new ResourceStream($resource);
    }

    /**
     * Create a new request.
     *
     * @param string              $method the HTTP method associated with the request
     * @param UriInterface|string $uri    the URI associated with the request
     */
    public function createRequest(string $method, $uri): RequestInterface
    {
        return Request::create($method, $uri);
    }
}
