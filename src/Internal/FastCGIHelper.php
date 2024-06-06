<?php

namespace phasync\Internal;

class FastCGIHelper
{
    public const VERSION_1     = 1;
    public const BEGIN_REQUEST = 1;
    public const RESPONDER     = 1;
    public const FCGI_PARAMS   = 4;
    public const FCGI_STDIN    = 5;

    public static function buildBeginRequest(): string
    {
        return \pack(
            'C2nC2',
            self::VERSION_1,           // version
            self::BEGIN_REQUEST,       // type
            1,                         // requestId
            0,                         // contentLength
            0                          // paddingLength
        ) . \pack(
            'nC5',
            self::RESPONDER,           // role
            0, 0, 0, 0, 0             // reserved
        );
    }

    public static function buildParams(array $params): string
    {
        $data = '';

        foreach ($params as $name => $value) {
            $data .= self::buildNameValuePair($name, $value);
        }

        $data .= \pack('C2nC2', self::VERSION_1, self::FCGI_PARAMS, 1, 0, 0);

        return $data;
    }

    public static function buildNameValuePair(string $name, string $value): string
    {
        $nameLength  = \strlen($name);
        $valueLength = \strlen($value);

        $nvpair = '';

        if ($nameLength < 128) {
            $nvpair .= \pack('C', $nameLength);
        } else {
            $nvpair .= \pack('N', $nameLength | 0x80000000);
        }

        if ($valueLength < 128) {
            $nvpair .= \pack('C', $valueLength);
        } else {
            $nvpair .= \pack('N', $valueLength | 0x80000000);
        }

        $nvpair .= $name . $value;

        return $nvpair;
    }

    public static function buildStdin(string $data): string
    {
        $length = \strlen($data);

        return \pack(
            'C2nC2',
            self::VERSION_1, self::FCGI_STDIN, 1, $length, 0
        ) . $data . \pack(
            'C2nC2',
            self::VERSION_1, self::FCGI_STDIN, 1, 0, 0
        );
    }

    public static function sendFastCGIRequest(string $socketPath, string $scriptFilename, string $data): string
    {
        $sock = \fsockopen("unix://{$socketPath}");
        if (!$sock) {
            throw new \RuntimeException('Failed to connect to FastCGI socket');
        }

        $params = [
            'SCRIPT_FILENAME' => $scriptFilename,
            'REQUEST_METHOD'  => 'POST',
            'CONTENT_TYPE'    => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH'  => \strlen($data),
        ];

        \fwrite($sock, self::buildBeginRequest());
        \fwrite($sock, self::buildParams($params));
        \fwrite($sock, self::buildStdin($data));

        $response = '';
        while (!\feof($sock)) {
            $response .= \fgets($sock, 1024);
        }

        \fclose($sock);

        return $response;
    }
}
