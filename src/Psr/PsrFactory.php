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

class PsrFactory implements UploadedFileFactoryInterface, ServerRequestFactoryInterface, ResponseFactoryInterface, RequestFactoryInterface, StreamFactoryInterface, UriFactoryInterface {

    public function createUploadedFile(StreamInterface $stream, ?int $size = null, int $error = \UPLOAD_ERR_OK, ?string $clientFilename = null, ?string $clientMediaType = null): UploadedFileInterface {
        return new UploadedFile($stream, $size, $error, $clientFilename, $clientMediaType);
    }

    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface {
        return new ServerRequest($method, $uri, $serverParams);
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface {
        return new Response($code, $reasonPhrase);
    }

    public function createUri(string $uri = ''): UriInterface {
        return new Uri($uri);
    }

    public function createStream(string $content = ''): StreamInterface {
        return new StringStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface {
        $fp = \fopen($filename, $mode);
        return $this->createStreamFromResource($fp);
    }

    public function createStreamFromResource($resource): StreamInterface {
        \stream_set_blocking($resource, false);
        return new ResourceStream($resource);
    }

    /**
     * Create a new request.
     *
     * @param string $method The HTTP method associated with the request.
     * @param UriInterface|string $uri The URI associated with the request. 
     */
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, $uri);
    }
}