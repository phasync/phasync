<?php
namespace phasync\Server;

use LogicException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * The HttpConnection class represents a connection between an HTTP
 * client (a browser or a reverse proxy) and this application. It manages
 * the request state, from parsing the incoming request line and HTTP
 * headers to buffering and parsing the request body.
 * 
 * This class works directly with the socket connection to the client
 * accessing the HTTP server. It only provides buffering for headers
 * and performs basic protocol validation.
 * 
 * @package phasync\Server
 */
class HttpConnection {

    /**
     * Mapping from HTTP status codes to status messages.
     */
    const RESPONSE_CODES = [
        100 => "Continue", 101 => "Switching Protocols", 102 => "Processing", 200 => "OK", 201 => "Created", 202 => "Accepted", 203 => "Non-Authoritative Information", 204 => "No Content", 205 => "Reset Content", 206 => "Partial Content", 207 => "Multi-Status", 300 => "Multiple Choices", 301 => "Moved Permanently", 302 => "Found", 303 => "See Other", 304 => "Not Modified", 305 => "Use Proxy", 306 => "(Unused)", 307 => "Temporary Redirect", 308 => "Permanent Redirect", 400 => "Bad Request", 401 => "Unauthorized", 402 => "Payment Required", 403 => "Forbidden", 404 => "Not Found", 405 => "Method Not Allowed", 406 => "Not Acceptable", 407 => "Proxy Authentication Required", 408 => "Request Timeout", 409 => "Conflict", 410 => "Gone", 411 => "Length Required", 412 => "Precondition Failed", 413 => "Request Entity Too Large", 414 => "Request-URI Too Long", 415 => "Unsupported Media Type", 416 => "Requested Range Not Satisfiable", 417 => "Expectation Failed", 418 => "I'm a teapot", 419 => "Authentication Timeout", 420 => "Enhance Your Calm", 422 => "Unprocessable Entity", 423 => "Locked", 424 => "Failed Dependency", 425 => "Unordered Collection", 426 => "Upgrade Required", 428 => "Precondition Required", 429 => "Too Many Requests", 431 => "Request Header Fields Too Large", 444 => "No Response", 449 => "Retry With", 450 => "Blocked by Windows Parental Controls", 451 => "Unavailable For Legal Reasons", 494 => "Request Header Too Large", 495 => "Cert Error", 496 => "No Cert", 497 => "HTTP to HTTPS", 499 => "Client Closed Request", 500 => "Internal Server Error", 501 => "Not Implemented", 502 => "Bad Gateway", 503 => "Service Unavailable", 504 => "Gateway Timeout", 505 => "HTTP Version Not Supported", 506 => "Variant Also Negotiates", 507 => "Insufficient Storage", 508 => "Loop Detected", 509 => "Bandwidth Limit Exceeded", 510 => "Not Extended", 511 => "Network Authentication Required", 598 => "Network read timeout error", 599 => "Network connect timeout error"
    ];

    /**
     * This value is true when the headers have been sent and the response
     * body is started to send.
     * 
     * @var bool
     */
    private bool $headersSent = false;

    /**
     * This value is true when the full response body has been sent and the
     * TcpConnection can no longer be used.
     * 
     * @var bool
     */
    private bool $completed = false;

    /**
     * The underlying TcpConnection for direct communication with the client
     * 
     * @var TcpConnection
     */
    private TcpConnection $connection;

    /**
     * Represents connection state in the same structure as
     * the PHP $_SERVER super-global.
     * 
     * @var array<string, array|string|int|float>
     */
    private array $server;

    /**
     * The HTTP protocol version as parsed from the request
     * 
     * @var string
     */
    private string $protocolVersion = '1.1';

    /**
     * The response status code. Can only be modified as long
     * as headers have not been sent.
     * 
     * @var int
     */
    private int $responseCode = 200;

    /**
     * The response status message. Can only be modified as
     * long as headers have not been sent.
     * 
     * @var string
     */
    private string $responseStatus = "Ok";

    /**
     * The response headers. These can be modified as long as
     * {@see HttpConnection::$headersSent} is false.
     * 
     * @var array<string, array<string>>
     */
    private array $responseHeaders = [];

    /**
     * Preserving the last set header case.
     * 
     * @var array
     */
    private array $responseHeaderCase = [];

    /**
     * When sending a response with a fixed Content-Length,
     * this number tracks how much remains before the response
     * is fully sent. If the value is -1 then chunked transfer
     * is being used.
     * 
     * @var int
     */
    private int $remainingLength = 0;

    public function __construct(TcpConnection $connection) {
        $this->connection = $connection;
        $requestHead = $connection->peek(32768);
        $headLength = \strlen($requestHead);
        $server = self::parseHead($requestHead);

        if ($server === null) {
            // Send a 400 Bad Request response
            $this->sendError(400, "Unable to parse request head");
            $connection->close();
            return;
        }

        // Ensure only the request body remains in the buffer
        $consumedLength = $headLength - \strlen($requestHead);
        $connection->read($consumedLength);

        if ($connection->peerName !== null) {
            [$remoteAddress, $remotePort] = \explode(":", $connection->peerName, 2);
            $server['REMOTE_ADDR'] = $remoteAddress;
            $server['REMOTE_PORT'] = $remotePort;
        }

        if (empty($server['SERVER_NAME'])) {
            $server['SERVER_NAME'] = $server['HTTP_HOST'] ?? '';
        }

        [$_, $this->protocolVersion] = \explode("/", $server['SERVER_PROTOCOL']);

        $this->server = $server;
    }

    public function sendHeaders(): void {
        $this->assertHeadersNotSent();

        if ($this->responseHeaders['transfer-encoding'] === ['chunked']) {
            $this->remainingLength = -1;
        } elseif (!empty($this->responseHeaders['content-length'][0])) {
            $this->remainingLength = (int) $this->getResponseHeader('content-length');
        } else {
            $this->remainingLength = -1;
            $this->setResponseHeader("Transfer-Encoding: chunked", false);
        }

        $lines = ['HTTP/' . $this->protocolVersion . ' ' . $this->responseCode . ' ' . $this->responseStatus . "\r\n"];
        foreach ($this->responseHeaders as $name => $values) {
            foreach ($values as $value) {
                $lines[] = $this->responseHeaderCase[$name] . ': ' . $value . "\r\n";
            }
        }

        $head = \implode("", $lines) . "\r\n";
        $this->headersSent = true;
        $this->connection->write($head);
    }

    public function end(string $chunk=''): void {
        if ($this->completed && $chunk === 0) {
            return;
        }
        $this->assertNotCompleted();
        $length = \strlen($chunk);
        if (!$this->headersSent) {
            // We can fully decide which encoding to use
            $this->setResponseHeader("Content-Length: " . \strlen($chunk));
        }
        $this->write($chunk);
        $this->completed = true;
    }

    public function write(string $chunk): void {
        $this->assertNotCompleted();
        if (!$this->headersSent) {
            $this->setResponseHeader("Transfer-Encoding: chunked");
            $this->sendHeaders();
        }

        $length = \strlen($chunk);

        if ($this->remainingLength === -1) {
            $this->connection->write(\dechex($length) . "\r\n" . $chunk . "\r\n");
        } else {
            $this->remainingLength -= $length;
            if ($this->remainingLength < 0) {
                throw new RuntimeException("Sending more content than Content-Length allows");
            }
            $this->connection->write($chunk);
            if ($this->remainingLength === 0) {
                $this->completed = true;
            }
        }
    }


    /**
     * Send a raw HTTP header
     * 
     * {@see \header()}
     * 
     * @param string $header 
     * @param bool $replace 
     * @param int $response_code 
     * @return void 
     */
    public function setResponseHeader(string $header, bool $replace = true, int $response_code = 0): void {
        $this->assertHeadersNotSent();

        $parts = \explode(":", $header, 2);

        if (isset($parts[1])) {
            // This is setting a header
            $parts[0] = \trim($parts[0]);
            $parts[1] = \trim($parts[1]);
            $lcHeader = \strtolower($parts[0]);
            $this->responseHeaderCase[$lcHeader] = $parts[0];
            if ($replace) {
                $this->responseHeaders[$lcHeader] = [ $parts[1] ];
            } else {
                $this->responseHeaders[$lcHeader][] = $parts[1];
            }
            if ($response_code !== 0) {
                $this->responseCode = $response_code;
                if (isset(self::RESPONSE_CODES[$response_code])) {
                    $this->responseStatus = self::RESPONSE_CODES[$response_code];                    
                } else {
                    $this->responseStatus = "";
                }
            }
        } elseif (\preg_match('/^HTTP\/(?<VERSION>[0-9]++\.[0-9]++)\ (?<CODE>[1-5][0-9]{2}+)\ (?<STATUS>[^\r\n]++)/', $header, $matches)) {
            $this->protocolVersion = $matches['VERSION'];
            $this->responseCode = \intval($matches['CODE']);
            $this->responseStatus = $matches['STATUS'];
        } else {
            throw new LogicException("Invalid header or status message format");
        }
    }

    /**
     * Remove previously set headers
     * 
     * {@see \header_remove()}
     * 
     * @param string|null $name 
     * @return void 
     * @throws LogicException 
     */
    public function removeResponseHeaders(string $name = null): void {
        $this->assertHeadersNotSent();

        if ($name === null) {
            $this->responseHeaders = [];
            $this->responseHeaderCase = [];
        } else {
            $lcName = \strtolower($name);
            unset($this->responseHeaders[$lcName], $this->responseHeaderCase[$lcName]);
        }
    }

    /**
     * Returns true when headers have been sent and it is no
     * longer possible to write more data.
     * 
     * {@see \headers_sent()}
     * 
     * @return bool 
     */
    public function isHeadersSent(): bool {
        return $this->headersSent;
    }

    /**
     * Returns the current HTTP protocol version.
     * 
     * @return string 
     */
    public function getProtocolVersion(): string {
        return $this->protocolVersion;
    }

    /**
     * Returns a list of response headers sent (or ready to send)
     * 
     * {@see \headers_list()}
     * 
     * @return array 
     */
    public function getResponseHeaders(): array {
        $result = [];
        foreach ($this->responseHeaders as $name => $values) {
            foreach ($values as $value) {
                $result[] = $this->responseHeaderCase[$name]. ': ' . $value;
            }
        }
        return $result;
    }

    /**
     * Returns a list of request headers received
     * 
     * @return array 
     */
    public function getRequestHeaders(): array {
        $result = [];
        foreach ($this->server['HEADERS'] as $name => $values) {
            foreach ($values as $value) {
                $result[] = $this->server['HEADERS_CASE'][$name] . ': ' . $value;
            }
        }
        return $result;
    }

    /**
     * Returns an array of response header values.
     * 
     * @param string $name 
     * @return null|array 
     */
    public function getResponseHeader(string $name): ?string {
        $lcName = \strtolower($name);
        if (!empty($this->responseHeaders[$lcName])) {
            return \implode(", ", $this->responseHeaders[$lcName]);
        }
        return null;
    }

    public function getRequestHeader(string $name): ?string {
        $lcName = \strtolower($name);
        if (!empty($this->server['HEADERS'][$lcName])) {
            return \implode(", ", $this->server['HEADERS'][$lcName]);
        }
        return null;
    }

    /**
     * Get the HTTP response code
     * 
     * {@see \http_response_code()}
     * 
     * @return int 
     */
    public function getResponseCode(): int {
        return $this->responseCode;
    }

    /**
     * Returns true when the full response including headers 
     * have been sent, and this HttpConnection instance is no
     * longer usable. A new HttpConnection instance may now be
     * created using the TcpConnection instance, if the header
     * `Connection: keep-alive` was used and neither the 
     * request or the response used `Connection: close`.
     * 
     * @return bool 
     */
    public function isCompleted(): bool {
        return $this->completed;
    }

    /**
     * Get access to the underlying TcpConnection for working with
     * the raw data stream. This is usable for example if the request
     * is upgraded to a Websocket connection.
     * 
     * @internal
     * @return TcpConnection 
     */
    public function getTcpConnection(): TcpConnection {
        return $this->connection;
    }

    protected function sendError(int $code, string $message = null): void {
        $this->assertHeadersNotSent();
        $this->removeResponseHeaders();
        $status = self::RESPONSE_CODES[$code] ?? $message ?? "Error";
        $message = $message ?? self::RESPONSE_CODES[$code] ?? "Internal Error";
        $this->setResponseHeader("HTTP/" . $this->protocolVersion . " " . $code . " " . $status, true, $code);
        $this->setResponseHeader("Content-Type: text/html");
        $this->setResponseHeader("Connection: close");
        $this->end(<<<HTML
            <!DOCTYPE html>
            <h1>$code $status</h1>
            <p>$message</p>
            HTML);
    }

    private function assertHeadersNotSent() {
        if ($this->headersSent) {
            throw new LogicException("Headers have been sent.");
        }
    }

    private function assertNotCompleted() {
        if ($this->completed) {
            throw new LogicException("HTTP connection has been completed");
        }
    }

    /**
     * Parses the head of the request and trims it from the $buffer string.
     * The returned array is according to the $_SERVER format as expected in
     * normal PHP applications.
     * 
     * @param string $buffer 
     * @return null|array 
     */
    private static function parseHead(string &$buffer): ?array {
        $headRegex = '/^(?<VERB>[A-Z]++)\ (?<URI>[^\s]++)\ (?<PROTOCOL>[a-zA-Z]++\/[0-9]++\.[0-9]++)\r?\n(?<HEADER>([\w-]++):\s*[^\r\n]*+\r?\n)*+(?<END_OF_HEAD>\r?\n)/s';
    
        if (\preg_match($headRegex, $buffer, $matches)) {
            $result = [
                'REQUEST_METHOD' => $matches['VERB'],
                'REQUEST_URI' => $matches['URI'],
                'SERVER_PROTOCOL' => $matches['PROTOCOL'],
                'HEADERS' => [],
                'HEADERS_CASE' => [],
            ];

            // Extract headers using a separate regex within the captured HEADERS group
            $headerRegex = '/(?<KEY>[\w-]++):\s*(?<VALUE>[^\r\n]*+)\r?\n/';
            if (isset($matches['HEADER']) && \preg_match_all($headerRegex, $matches['HEADER'], $headerMatches, \PREG_SET_ORDER)) {
                foreach ($headerMatches as $header) {
                    $lcName = \strtolower($header['KEY']);
                    $result['HEADERS'][$lcName][] = $header['VALUE'];
                    $result['HEADERS_CASE'][$lcName] = $header['KEY'];
                    $phpName = 'HTTP_' . \strtoupper(\str_replace('-', '_', $header['KEY']));
                    $result[$phpName] = $header['VALUE'];
                }
            }
    
            // Parse additional URI components
            $uriComponents = \parse_url($matches['URI']);
            if (!empty($uriComponents['query'])) {
                $result['QUERY_STRING'] = $uriComponents['query'];
            }            
            if (!empty($uriComponents['path'])) {
                $result['SCRIPT_NAME'] = $uriComponents['path'];
            }
    
            // Update the buffer to remove the parsed head
            $buffer = \substr($buffer, \strlen($matches[0]));
    
            return $result;
        }
        return null;
    }

}