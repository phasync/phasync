<?php

namespace phasync\Util\FastCGI;

use phasync\Util\ObjectPoolInterface;
use phasync\Util\ObjectPoolTrait;
use phasync\Util\StringBuffer;

final class Record implements ObjectPoolInterface
{
    use ObjectPoolTrait;

    /**
     * FastCGI record types
     */
    public const FCGI_BEGIN_REQUEST     = 1;
    public const FCGI_ABORT_REQUEST     = 2;
    public const FCGI_END_REQUEST       = 3;
    public const FCGI_PARAMS            = 4;
    public const FCGI_STDIN             = 5;
    public const FCGI_STDOUT            = 6;
    public const FCGI_STDERR            = 7;
    public const FCGI_DATA              = 8;
    public const FCGI_GET_VALUES        = 9;
    public const FCGI_GET_VALUES_RESULT = 10;
    public const FCGI_UNKNOWN_TYPE      = 11;

    /**
     * FastCGI roles
     */
    public const FCGI_ROLE_RESPONDER                = 1;
    public const FCGI_ROLE_AUTHORIZER               = 2;
    public const FCGI_ROLE_FILTER                   = 3;

    /**
     * FastCGI protocol status
     */
    public const FCGI_PROT_STATUS_REQUEST_COMPLETE  = 0;
    public const FCGI_PROT_STATUS_CANT_MPX_CONN     = 1;
    public const FCGI_PROT_STATUS_OVERLOADED        = 2;
    public const FCGI_PROT_STATUS_UNKNOWN_ROLE      = 3;

    /**
     * FastCGI flags
     */
    public const FCGI_KEEP_CONN         = 1;

    public const TYPES = [
        self::FCGI_BEGIN_REQUEST     => 'FCGI_BEGIN_REQUEST',
        self::FCGI_ABORT_REQUEST     => 'FCGI_ABORT_REQUEST',
        self::FCGI_END_REQUEST       => 'FCGI_END_REQUEST',
        self::FCGI_PARAMS            => 'FCGI_PARAMS',
        self::FCGI_STDIN             => 'FCGI_STDIN',
        self::FCGI_STDOUT            => 'FCGI_STDOUT',
        self::FCGI_STDERR            => 'FCGI_STDERR',
        self::FCGI_DATA              => 'FCGI_DATA',
        self::FCGI_GET_VALUES        => 'FCGI_GET_VALUES',
        self::FCGI_GET_VALUES_RESULT => 'FCGI_VALUES_RESULT',
        self::FCGI_UNKNOWN_TYPE      => 'FCGI_UNKNOWN_TYPE',
    ];

    private const PADDINGS = [
        '',
        "\0",
        "\0\0",
        "\0\0\0",
        "\0\0\0\0",
        "\0\0\0\0\0",
        "\0\0\0\0\0\0",
        "\0\0\0\0\0\0\0",
    ];

    public int $version = 1;
    public int $type;
    public int $requestId;
    public string $content = '';
    private bool $pooled   = false;

    public function returnToPool(): void
    {
        $this->assertNotPooled();
        $this->pooled                       = true;
        $this->content                      = '';
        self::$pool[self::$instanceCount++] = $this;
    }

    /**
     * Parse a Record instance from a StringBuffer object.
     *
     * @throws \OutOfBoundsException
     */
    public static function parse(StringBuffer $buffer): ?Record
    {
        $chunk = $buffer->readFixed(8);
        if (null === $chunk) {
            return null;
        }
        $instance            = self::create();
        $unpacked            = \unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength', $chunk);
        $instance->version   = $unpacked['version'];
        $instance->type      = $unpacked['type'];
        $instance->requestId = $unpacked['requestId'];
        $contentLength       = $unpacked['contentLength'];
        $paddingLength       = $unpacked['paddingLength'];
        $payloadLength       = $contentLength + $paddingLength;
        if ($payloadLength > 0) {
            $content = $buffer->readFixed($payloadLength);
            if (null === $content) {
                $buffer->unread($chunk);

                return null;
            }
            $instance->content = \substr($content, 0, $contentLength);
        }

        return $instance;
    }

    /**
     * Create a new instance of Record
     */
    public static function create(): Record
    {
        $instance         = self::popInstance() ?? new self();
        $instance->pooled = false;

        return $instance;
    }

    /**
     * Use {@see Record::create()} to create an instance of Record.
     *
     * @return void
     */
    private function __construct()
    {
    }

    public function setBeginRequest(int $role=self::FCGI_ROLE_RESPONDER, int $flags=0): void
    {
        $this->type    = self::FCGI_BEGIN_REQUEST;
        $this->content = \pack('nCx5', $role, $flags);
    }

    public function getBeginRequest(int &$role, int &$flags): void
    {
        $unpacked = \unpack('nrole/Cflags', $this->content);
        $role     = $unpacked['role'];
        $flags    = $unpacked['flags'];
    }

    public function setAbortRequest(): void
    {
        $this->type    = self::FCGI_ABORT_REQUEST;
        $this->content = '';
    }

    public function setEndRequest(int $appStatus=0, int $protocolStatus=self::FCGI_PROT_STATUS_REQUEST_COMPLETE): void
    {
        $this->type    = self::FCGI_END_REQUEST;
        $this->content = \pack('NCx3', $appStatus, $protocolStatus);
    }

    public function getEndRequest(int &$appStatus, int &$protocolStatus): void
    {
        $unpacked       = \unpack('NappStatus/CprotocolStatus', $this->content);
        $appStatus      = $unpacked['appStatus'];
        $protocolStatus = $unpacked['protocolStatus'];
    }

    public function setParams(array $params): void
    {
        $this->type = self::FCGI_PARAMS;
        $this->setDictionary($params);
    }

    public function getParams(): array
    {
        return $this->getDictionary();
    }

    public function setStdin(string $content): void
    {
        $this->type    = self::FCGI_STDIN;
        $this->content = $content;
    }

    public function getStdin(): string
    {
        return $this->content;
    }

    public function setStdout(string $content): void
    {
        $this->type    = self::FCGI_STDOUT;
        $this->content = $content;
    }

    public function getStdout(): string
    {
        return $this->content;
    }

    public function setStderr(string $content): void
    {
        $this->type    = self::FCGI_STDERR;
        $this->content = $content;
    }

    public function getStderr(): string
    {
        return $this->content;
    }

    public function setGetValues(array $keys): void
    {
        $this->type = self::FCGI_GET_VALUES;
        $dict       = [];
        foreach ($keys as $v) {
            $dict[$v] = '';
        }
        $this->setDictionary($dict);
    }

    public function getGetValues(): array
    {
        return \array_keys($this->getDictionary());
    }

    public function setGetValuesResult(array $values): void
    {
        $this->type = self::FCGI_GET_VALUES_RESULT;
        $this->setDictionary($values);
    }

    public function getGetValuesResult(): array
    {
        return $this->getDictionary();
    }

    /**
     * Encode the content field from an associative array of key
     * and value pairs.
     *
     * @param array<string,string> $values
     */
    private function setDictionary(array $values): void
    {
        $this->assertNotPooled();
        $format = '';
        $args   = [];
        foreach ($values as $name => $value) {
            $nameLength  = \strlen($name);
            $valueLength = \strlen($value);
            $format .= ($nameLength < 128 ? 'C' : 'N')
                    . ($valueLength < 128 ? 'C' : 'N')
                    . 'a*'
                    . 'a*';
            $args[] = $nameLength < 128 ? $nameLength : $nameLength | 0x80000000;
            $args[] = $valueLength < 128 ? $valueLength : $valueLength | 0x80000000;
            $args[] = $name;
            $args[] = $value;
        }
        $this->content = \pack($format, ...$args);
    }

    /**
     * Decode the content field to an associative array of values
     *
     * @return array<string,string>
     */
    private function getDictionary(): array
    {
        $this->assertNotPooled();
        $values        = [];
        $offset        = 0;
        $contentLength = \strlen($this->content);

        while ($offset < $contentLength) {
            // Decode name length
            $nameLength = \ord($this->content[$offset]);
            if ($nameLength & 0x80) {
                $nameLength = \unpack('N', \substr($this->content, $offset, 4))[1] & 0x7FFFFFFF;
                $offset += 4;
            } else {
                ++$offset;
            }

            // Decode value length
            $valueLength = \ord($this->content[$offset]);
            if ($valueLength & 0x80) {
                $valueLength = \unpack('N', \substr($this->content, $offset, 4))[1] & 0x7FFFFFFF;
                $offset += 4;
            } else {
                ++$offset;
            }

            // Extract name and value
            $name = \substr($this->content, $offset, $nameLength);
            $offset += $nameLength;
            $value = \substr($this->content, $offset, $valueLength);
            $offset += $valueLength;

            // Store the pair in the values array
            $values[$name] = $value;
        }

        return $values;
    }

    /**
     * Encode the record to a binary representation
     */
    public function toString(): string
    {
        $contentLength = \strlen($this->content);
        $paddingLength = (8 - ($contentLength % 8)) % 8;

        return \pack('CCnnCx',
            $this->version,
            $this->type,
            $this->requestId,
            $contentLength,
            $paddingLength
        ) . $this->content . self::PADDINGS[$paddingLength];
    }

    private function assertNotPooled(): void
    {
        if ($this->pooled) {
            throw new \LogicException("Can't use this instance after it was returned to the object pool");
        }
    }
}
