<?php
namespace phasync\HttpClient;

use Charm\Options\UnknownOptionException;
use Charm\Options\IllegalOperationException;
use phasync\HttpClient\CurlResponse;
use phasync\HttpClient\HttpClientOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

final class HttpClient {

    public readonly HttpClientOptions $options;

    public function __construct(array|HttpClientOptions $options=[]) {
        $this->options = HttpClientOptions::create($options);
    }

    /**
     * Perform a request
     * @param string|UriInterface $url The URL to fetch from
     * @param string $method The method
     * @param null|array|HttpClientOptions $options 
     * @return CurlResponse 
     * @throws UnknownOptionException 
     * @throws IllegalOperationException 
     */
    public function request(string $method, string|UriInterface $url, mixed $requestData = null, array|HttpClientOptions|null $options=null): ResponseInterface {
        return new CurlResponse($method, $url, $requestData, $options ?? $this->options);
    }

    public function get(string|UriInterface $url, array|HttpClientOptions|null $options=null): ResponseInterface {
        return $this->request('GET', $url, null, $options);
    }

    public function post(string|UriInterface $url, mixed $requestData, array|HttpClientOptions|null $options=null): ResponseInterface {
        return $this->request('POST', $url, $requestData, $options);
    }

    public function put(string|UriInterface $url, mixed $requestData, array|HttpClientOptions|null $options=null): ResponseInterface {
        return $this->request('PUT', $url, $requestData, $options);
    }

}