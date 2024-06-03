<?php

namespace phasync\HttpClient;

use Charm\Options\IllegalOperationException;
use Charm\Options\UnknownOptionException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * @deprecated Sorry, this is not tested enough yet. It's just a proof of concept.
 */
final class HttpClient
{
    public readonly HttpClientOptions $options;

    public function __construct(array|HttpClientOptions $defaultRequestOptions=[])
    {
        $this->options = HttpClientOptions::create($defaultRequestOptions);
    }

    /**
     * Perform an HTTP request
     *
     * @param string|UriInterface $url    The URL to fetch from
     * @param string              $method The method
     *
     * @throws UnknownOptionException
     * @throws IllegalOperationException
     *
     * @return CurlResponse
     */
    public function request(string $method, string|UriInterface $url, mixed $requestData = null, array|HttpClientOptions|null $options=null): ResponseInterface
    {
        return new CurlResponse($method, $url, $requestData, $this->options->overrideFrom($options));
    }

    public function get(string|UriInterface $url, array|HttpClientOptions|null $options=null): ResponseInterface
    {
        return $this->request('GET', $url, null, $this->options->overrideFrom($options));
    }

    public function post(string|UriInterface $url, mixed $requestData, array|HttpClientOptions|null $options=null): ResponseInterface
    {
        return $this->request('POST', $url, $requestData, $this->options->overrideFrom($options));
    }

    public function put(string|UriInterface $url, mixed $requestData, array|HttpClientOptions|null $options=null): ResponseInterface
    {
        return $this->request('PUT', $url, $requestData, $this->options->overrideFrom($options));
    }
}
