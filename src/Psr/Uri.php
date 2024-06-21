<?php

namespace phasync\Psr;

use function parse_url;
use Psr\Http\Message\UriInterface;

/**
 *	Class simplifies working with URIs
 */
class Uri implements UriInterface
{
    protected string $uri;

    public const SCHEME_PORTS = [
        'ftp'     => 21,
        'ssh'     => 22,
        'telnet'  => 23,
        'smtp'    => 25,
        'gopher'  => 70,
        'finger'  => 79,
        'http'    => 80,
        'rtelnet' => 107,
        'pop3'    => 110,
        'sftp'    => 115,
        'nntp'    => 119,
        'ntp'     => 123,
        'imap'    => 143,
        'snmp'    => 161,
        'irc'     => 194,
        'ldap'    => 389,
        'smtpe'   => 420,
        'https'   => 443,
        'ftps'    => 990,
        'imaps'   => 993,
        'pop3s'   => 995,
        'wins'    => 1512,
        'rtmp'    => 1935,
    ];

    /**
     * Configure the UriTrait
     *
     * @param string|Stringable|UriInterface $uri An absolute URL
     */
    public function __construct(string|UriInterface $uri)
    {
        $this->uri = (string) $uri;
    }

    /**
     * Get the scheme of the url
     *
     * @see Psr\Http\Message\UriInterface::getScheme()
     */
    public function getScheme(): string
    {
        return \parse_url($this->uri, \PHP_URL_SCHEME);
    }

    /**
     * Get the hostname of the url
     *
     * @return mixed
     */
    public function getHost(): string
    {
        return \parse_url($this->uri, \PHP_URL_HOST) ?? '';
    }

    /**
     * Get the user of the url
     */
    public function getUser(): string
    {
        return \parse_url($this->uri, \PHP_URL_USER) ?? '';
    }

    /**
     * Get the password of the url
     */
    public function getPassword(): ?string
    {
        return \parse_url($this->uri, \PHP_URL_PASS);
    }

    /**
     *	Parse out the value from the query string and return it.
     *
     * @param string $param The name of the parameter to remove
     */
    public function getParam($param)
    {
        $parsed = \parse_url($this->uri);
        if (empty($parsed['query'])) {
            return null;
        }

        $parts = static::parseQueryString($parsed['query']);

        return $parts[$param] ?? null;
    }

    /**
     * @see Psr\Http\Message\UriInterface::getAuthority()
     */
    public function getAuthority(): string
    {
        $parsed    = \parse_url($this->uri);
        $authority = $this->getHost();
        if (!$authority) {
            return '';
        }
        if ($userInfo = $this->getUserInfo()) {
            $authority = $userInfo . '@' . $authority;
        }
        if (null !== ($port = $this->getPort())) {
            $authority .= ':' . $port;
        }

        return $authority;
    }

    /**
     * @see Psr\Http\Message\UriInterface::getUserInfo()
     */
    public function getUserInfo(): string
    {
        if ($user = $this->getUser()) {
            if ($pass = $this->getPassword()) {
                return $user . ':' . $pass;
            }

            return $user;
        }

        return '';
    }

    /**
     * @see Psr\Http\Message\UriInterface::getPort()
     */
    public function getPort(): ?int
    {
        return \parse_url($this->uri, \PHP_URL_PORT);
    }

    /**
     * Returns the path section of the URL up until the query parameter and fragment
     */
    public function getPath(): string
    {
        return \parse_url($this->uri, \PHP_URL_PATH) ?? '';
    }

    /**
     *	Get the entire query part of the url (from the ? until the fragment #)
     *
     *  @see Psr\Http\Message\UriInterface::getQuery()
     */
    public function getQuery(): string
    {
        return \parse_url($this->uri, \PHP_URL_QUERY) ?? '';
    }

    /**
     * Get the url fragment.
     *
     * @see Psr\Http\Message\UriInterface::getFragment()
     */
    public function getFragment(): string
    {
        return \parse_url($this->uri, \PHP_URL_FRAGMENT) ?? '';
    }

    /**
     * Get the url fragment.
     *
     * @see Psr\Http\Message\UriInterface::withScheme()
     *
     * @return mixed
     */
    public function withScheme($scheme): UriInterface
    {
        $c = clone $this;
        $c->setScheme($scheme);

        return $c;
    }

    /**
     * Get the url fragment.
     *
     * @see Psr\Http\Message\UriInterface::withUserInfo()
     *
     * @return mixed
     */
    public function withUserInfo($user, $password = null): UriInterface
    {
        $c = clone $this;
        $c->setUserInfo($user, $password);

        return $c;
    }

    /**
     * Get the url fragment.
     *
     * @see Psr\Http\Message\UriInterface::withHost()
     *
     * @return mixed
     */
    public function withHost($host): UriInterface
    {
        if (false !== \strpos($host, '/') || \filter_var('http://' . $host, \FILTER_VALIDATE_URL) !== 'http://' . $host) {
            throw new \InvalidArgumentException('Invalid host name');
        }

        $c = clone $this;
        $c->setHost($host);

        return $c;
    }

    /**
     * Get the url fragment.
     *
     * @see Psr\Http\Message\UriInterface::withPort()
     *
     * @return mixed
     */
    public function withPort($port): UriInterface
    {
        $c = clone $this;
        $c->setPort($port);

        return $c;
    }

    /**
     * Set the path
     *
     * @see Psr\Http\Message\UriInterface::withPath()
     */
    public function withPath($path): UriInterface
    {
        $c = clone $this;
        $c->setPath($path);

        return $c;
    }

    /**
     * Get the url fragment.
     *
     * @see Psr\Http\Message\UriInterface::withQuery()
     */
    public function withQuery($query): UriInterface
    {
        $c = clone $this;
        $c->setQuery($query);

        return $c;
    }

    /**
     * Get the url fragment.
     *
     * @see Psr\Http\Message\UriInterface::withFragment()
     */
    public function withFragment($fragment): UriInterface
    {
        $c = clone $this;
        $c->setFragment($fragment);

        return $c;
    }

    /**
     * When echoing this class, the URL will be displayed.
     *
     * @see Psr\Http\Message\UriInterface::__toString()
     */
    public function __toString()
    {
        return $this->uri;
    }

    /**
     * @see \JsonSerializable::jsonSerialize()
     */
    public function jsonSerialize()
    {
        return $this->__toString();
    }

    /**
     *	Set the hostname of the url
     *
     * @param mixed $value a string or an array to insert as value
     *
     * @return $this
     */
    protected function setHost($value)
    {
        $parsed         = \parse_url($this->uri);
        $parsed['host'] = $value;
        $this->uri      = static::buildUriString($parsed);

        return $this;
    }

    /**
     *	Set the scheme of the url (http/https/rtmp etc)
     *
     * @return $this
     */
    protected function setScheme(?string $scheme)
    {
        if ('' !== \preg_replace('/^[a-z][a-z0-9:.-_]*[a-z0-9]/', '', $scheme)) {
            throw new \InvalidArgumentException("Invalid scheme '$scheme'");
        }
        $parsed           = \parse_url($this->uri);
        $parsed['scheme'] = $scheme;
        $this->uri        = static::buildUriString($parsed);

        return $this;
    }

    /**
     * Set the user info of the url
     *
     * @param string      $user     the user name to use for authority
     * @param string|null $password the password associated with $user
     */
    protected function setUserInfo(string $user, ?string $password=null)
    {
        $parsed = \parse_url($this->uri);
        unset($parsed['user']);
        unset($parsed['pass']);
        if ('' !== $user) {
            $parsed['user'] = $user;
            if (null !== $password) {
                $parsed['pass'] = $password;
            }
        }
        $this->uri = static::buildUriString($parsed);

        return $this;
    }

    /**
     *	Set the port of the url (http/https/rtmp etc)
     *
     * @param int|null $port
     *
     * @return $this
     */
    protected function setPort($port)
    {
        $parsed         = \parse_url($this->uri);
        $parsed['port'] = $port;
        $this->uri      = static::buildUriString($parsed);

        return $this;
    }

    /**
     *	Set the path of the url
     *
     * @return $this
     */
    protected function setPath(string $path)
    {
        $parsed = \parse_url($this->uri);

        if ('' === $path || '/' === $path) {
            // this is a special case
            $parsed['path'] = $path;
        } elseif ('/' === $path[0]) {
            // their path is an absolute path
            $parsed['path'] = static::pathShorten($path);
        } else {
            $parsed['path'] = static::pathShorten(static::pathDirName($parsed['path'] ?? '/') . $path);
        }

        $this->uri = static::buildUriString($parsed);

        return $this;
    }

    /**
     *	Set the fragment part of the query (after the #)
     *
     * @param string $value A string
     *
     * @return $this
     */
    protected function setFragment(string $value)
    {
        $parsed             = \parse_url($this->uri);
        $parsed['fragment'] = $value;
        $this->uri          = static::buildUriString($parsed);

        return $this;
    }

    /**
     *	Remove the fragment part of the query (after the #). This removes the entire fragment.
     *
     * @return $this
     */
    protected function unsetFragment()
    {
        $parsed = \parse_url($this->uri);
        unset($parsed['fragment']);
        $this->uri = static::buildUriString($parsed);

        return $this;
    }

    /**
     *	Set the entire query (from the ? until the fragment #)
     *
     * @param string|array $value a string or an array to insert as fragment
     *
     * @return $this
     */
    protected function setQuery($value)
    {
        $parsed          = \parse_url($this->uri);
        $parsed['query'] = $value;
        $this->uri       = static::buildUriString($parsed);

        return $this;
    }

    /**
     *	Add or replace a part of the query string
     *
     * @param string       $param The name of the parameter to change
     * @param string|array $value a string or an array to insert as value
     *
     * @return $this
     */
    protected function setParam($param, $value)
    {
        $parsed = \parse_url($this->uri);
        if (empty($parsed['query'])) {
            $query = [];
        } else {
            $query = static::parseQueryString($parsed['query']);
        }

        $query[$param] = \strval($value);

        $parsed['query'] = \http_build_query($query, '', '&');
        $this->uri       = static::buildUriString($parsed);

        return $this;
    }

    /**
     *	Remove a parameter from the query string
     *
     * @param string $param The name of the parameter to remove
     *
     * @return $this
     */
    protected function unsetParam($param)
    {
        $parsed = \parse_url($this->uri);
        if (empty($parsed['query'])) {
            return new static($this->uri);
        }
        $query = self::parseQueryString($parsed['query']);
        unset($query[$param]);

        $parsed['query'] = \http_build_query($query, '', '&');
        $this->uri       = static::buildUriString($parsed);

        return $this;
    }

    /**
     *	Removes all parameters from the query string
     *
     * @return $this
     */
    protected function unsetQuery()
    {
        $parsed = \parse_url($this->uri);
        unset($parsed['query']);
        $this->uri = static::buildUriString($parsed);

        return $this;
    }

    /**
     * Uses the multibyte parse_str version if it exists.
     */
    protected static function parseQueryString(string $queryString): array
    {
        $query = null;
        if (\function_exists('mb_parse_str')) {
            if (!\mb_parse_str($queryString, $query)) {
                return [];
            }
        } else {
            if (!\parse_str($queryString, $query)) {
                return [];
            }
        }

        return $query;
    }

    /**
     * Builds a complete URL from a parsed URL, according to parse_url().
     */
    protected static function buildUriString(array $parsed): string
    {
        if (empty($parsed['scheme'])) {
            throw new \InvalidArgumentException('No scheme in URI');
        }
        if (empty($parsed['host'])) {
            throw new \InvalidArgumentException('No host in URI');
        }

        $scheme = !empty($parsed['scheme']) ? $parsed['scheme'] . ':' : '';

        $authority = $parsed['host'] ?? '';

        if (!empty($parsed['user']) && !empty($parsed['pass'])) {
            $authority = $parsed['user'] . ':' . $parsed['pass'] . '@' . $authority;
        } elseif (!empty($parsed['user'])) {
            $authority = $parsed['user'] . '@' . $authority;
        }

        if (!empty($parsed['port'])) {
            if (!empty($parsed['scheme']) && Uri::SCHEME_PORTS[$parsed['scheme']] !== $parsed['port']) {
                $authority .= ':' . $parsed['port'];
            }
        }

        if ('' !== $authority) {
            $authority = '//' . $authority;
        }

        $path = !empty($parsed['path']) ? $parsed['path'] : '';
        if ('' !== $path) {
            if ('' !== $authority) {
                if ('/' !== $path[0]) {
                    $path = '/' . $path;
                }
            } else {
                if ('/' === $path[0]) {
                    $path = '/' . \ltrim($path, '/');
                }
            }
        }
        $query    = !empty($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = !empty($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return $scheme . $authority . $path . $query . $fragment;
    }

    /**
     * Dirname part of the path
     */
    protected static function pathDirName(string $path): string
    {
        return \preg_replace('/[^\/]+$/', '', $path);
    }

    /**
     * Remove any /./ and resolve any /../ components of a path.
     */
    protected static function pathShorten(string $path): string
    {
        if ('' === $path) {
            return $path;
        }

        if ('/' !== $path[0]) {
            throw new \InvalidArgumentException("Invalid path '$path'. Path must be absolute.");
        }

        // remove leading / and any double slashes in the path
        $path   = \preg_replace('/^\/+|(?<=\/)\/+/', '', $path);
        $parts  = \explode('/', $path);
        $result = [];
        $length = \count($parts);
        for ($i = 0; $i < $length; ++$i) {
            switch ($parts[$i]) {
                case '':
                    if ($i === $length - 1) {
                        $result[] = '';
                    }
                    break;
                case '.':
                    if ($i === $length - 1) {
                        $result[] = '';
                    }
                    break;
                case '..':
                    \array_pop($result);
                    if ($i === $length - 1) {
                        $result[] = '';
                    }
                    break;
                default:
                    $result[] = $parts[$i];
                    break;
            }
        }

        return '/' . \implode('/', $result);
    }
}
