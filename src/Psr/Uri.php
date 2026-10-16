<?php

namespace phasync\Psr;

use function parse_url;
use Psr\Http\Message\UriInterface;

/**
 * A PSR-7 URI.
 *
 * Built from a string or another `UriInterface`. Immutable: the `with...()` methods return a modified copy.
 *
 * ```php
 * $uri = new Uri('https://example.com/search?q=phasync');
 * $uri->getHost();                      // "example.com"
 * (string) $uri->withPath('/docs');     // "https://example.com/docs?q=phasync"
 * ```
 *
 * @see Request::getUri
 *
 * @topic http-client
 *
 * @example examples/Uri/1-parts-of-a-uri.php The parts of a URI
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

    /** Characters RFC 3986 does not allow in a path (anything but pchar and "/"), and a "%" not followed by two hex digits. */
    private const PATH_UNSAFE = '/[^a-zA-Z0-9_\-.~!$&\'()*+,;=:@\/%]++|%(?![0-9a-fA-F]{2})/';

    /** The same for a query or fragment, which also allow "?". */
    private const QUERY_UNSAFE = '/[^a-zA-Z0-9_\-.~!$&\'()*+,;=:@\/?%]++|%(?![0-9a-fA-F]{2})/';

    /** The same for user info, where ":" separates user and password and so is not allowed within either. */
    private const USERINFO_UNSAFE = '/[^a-zA-Z0-9_\-.~!$&\'()*+,;=%]++|%(?![0-9a-fA-F]{2})/';

    /**
     * Creates a URI from `$uri`.
     *
     * The string form is normalised as the getters are: the scheme and host in lower case, no port when it is the
     * scheme's default, and characters RFC 3986 does not allow percent-encoded (an existing "%xx" is never encoded twice).
     * A URI that is already normal is kept exactly as given.
     *
     * @param string|UriInterface $uri an absolute URL, or a relative reference
     *
     * @throws \InvalidArgumentException when `$uri` cannot be parsed
     */
    public function __construct(string|UriInterface $uri)
    {
        $this->uri = (string) $uri;
        $parsed    = \parse_url($this->uri);
        if (false === $parsed) {
            throw new \InvalidArgumentException("Unable to parse URI '{$this->uri}'");
        }
        $changed = false;
        foreach (['scheme' => null, 'host' => null, 'user' => self::USERINFO_UNSAFE, 'pass' => self::USERINFO_UNSAFE, 'path' => self::PATH_UNSAFE, 'query' => self::QUERY_UNSAFE, 'fragment' => self::QUERY_UNSAFE] as $part => $unsafe) {
            if (!isset($parsed[$part])) {
                continue;
            }
            $value = null === $unsafe ? \strtolower($parsed[$part]) : static::encode($parsed[$part], $unsafe);
            if ($value !== $parsed[$part]) {
                $parsed[$part] = $value;
                $changed       = true;
            }
        }
        if (isset($parsed['port'], $parsed['scheme']) && (self::SCHEME_PORTS[$parsed['scheme']] ?? null) === $parsed['port']) {
            $changed = true;
        }
        if ($changed) {
            $this->uri = static::buildUriString($parsed);
        }
    }

    /**
     * Percent-encodes the characters matched by `$unsafe`.
     */
    protected static function encode(string $value, string $unsafe): string
    {
        if (!\preg_match($unsafe, $value)) {
            return $value;
        }

        return \preg_replace_callback($unsafe, static fn (array $m): string => \rawurlencode($m[0]), $value);
    }

    /**
     * Returns the scheme of the URI, lower case.
     *
     * @see Psr\Http\Message\UriInterface::getScheme()
     */
    public function getScheme(): string
    {
        return \strtolower(\parse_url($this->uri, \PHP_URL_SCHEME) ?? '');
    }

    /**
     * Returns the host of the URI, lower case.
     */
    public function getHost(): string
    {
        return \strtolower(\parse_url($this->uri, \PHP_URL_HOST) ?? '');
    }

    /**
     * Returns the user of the URI.
     */
    public function getUser(): string
    {
        return \parse_url($this->uri, \PHP_URL_USER) ?? '';
    }

    /**
     * Returns the password of the URI.
     */
    public function getPassword(): ?string
    {
        return \parse_url($this->uri, \PHP_URL_PASS);
    }

    /**
     * Returns the value of the query parameter `$param`, an array for `name[]` parameters, or null when it is not there.
     *
     * @param string $param the name of the parameter
     *
     * @example examples/Uri.getParam/1-one-query-parameter.php One parameter of the query
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
        $authority = $this->getHost();
        if ('' === $authority) {
            return '';
        }
        if ('' !== ($userInfo = $this->getUserInfo())) {
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
        $user = $this->getUser();
        if ('' === $user) {
            return '';
        }
        $pass = $this->getPassword();

        return null === $pass || '' === $pass ? $user : $user . ':' . $pass;
    }

    /**
     * @see Psr\Http\Message\UriInterface::getPort()
     */
    public function getPort(): ?int
    {
        $port = \parse_url($this->uri, \PHP_URL_PORT);
        if (null === $port) {
            return null;
        }
        $scheme = $this->getScheme();

        // MUST return null when the port is the standard port for the scheme
        return isset(self::SCHEME_PORTS[$scheme]) && self::SCHEME_PORTS[$scheme] === $port ? null : $port;
    }

    /**
     * Returns the path of the URI, up to the query and fragment.
     */
    public function getPath(): string
    {
        $path = \parse_url($this->uri, \PHP_URL_PATH) ?? '';
        // Several leading slashes would make the path look like an authority when used on its own
        if (isset($path[1]) && '/' === $path[0] && '/' === $path[1]) {
            return '/' . \ltrim($path, '/');
        }

        return $path;
    }

    /**
     * Returns the query of the URI: what follows the "?", up to the fragment.
     *
     *  @see Psr\Http\Message\UriInterface::getQuery()
     */
    public function getQuery(): string
    {
        return \parse_url($this->uri, \PHP_URL_QUERY) ?? '';
    }

    /**
     * Returns the fragment of the URI: what follows the "#".
     *
     * @see Psr\Http\Message\UriInterface::getFragment()
     */
    public function getFragment(): string
    {
        return \parse_url($this->uri, \PHP_URL_FRAGMENT) ?? '';
    }

    /**
     * Returns a copy of the URI with the scheme replaced.
     *
     * @see Psr\Http\Message\UriInterface::withScheme()
     */
    public function withScheme($scheme): UriInterface
    {
        $c = clone $this;
        $c->setScheme($scheme);

        return $c;
    }

    /**
     * Returns a copy of the URI with the user and password replaced.
     *
     * @see Psr\Http\Message\UriInterface::withUserInfo()
     */
    public function withUserInfo($user, $password = null): UriInterface
    {
        $c = clone $this;
        $c->setUserInfo($user, $password);

        return $c;
    }

    /**
     * Returns a copy of the URI with the host replaced.
     *
     * @see Psr\Http\Message\UriInterface::withHost()
     */
    public function withHost($host): UriInterface
    {
        if (!\is_string($host) || ('' !== $host && (false !== \strpos($host, '/') || \filter_var('http://' . $host, \FILTER_VALIDATE_URL) !== 'http://' . $host))) {
            throw new \InvalidArgumentException('Invalid host ' . (\is_string($host) ? \var_export($host, true) : '(' . \get_debug_type($host) . ')') . ': expected a host name or IP address, or "" to remove the host');
        }

        $c = clone $this;
        $c->setHost($host);

        return $c;
    }

    /**
     * Returns a copy of the URI with the port replaced; null removes it.
     *
     * @see Psr\Http\Message\UriInterface::withPort()
     */
    public function withPort($port): UriInterface
    {
        $c = clone $this;
        $c->setPort($port);

        return $c;
    }

    /**
     * Returns a copy of the URI with the path replaced.
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
     * Returns a copy of the URI with the query replaced.
     *
     * @see Psr\Http\Message\UriInterface::withQuery()
     *
     * @example examples/Uri.withQuery/1-replace-the-query.php Replace the query
     */
    public function withQuery($query): UriInterface
    {
        $c = clone $this;
        $c->setQuery($query);

        return $c;
    }

    /**
     * Returns a copy of the URI with the fragment replaced.
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
     * Returns the URI as a string.
     *
     * @see Psr\Http\Message\UriInterface::__toString()
     */
    public function __toString()
    {
        return $this->uri;
    }

    /**
     * Returns the URI as a string, like `__toString()`.
     *
     * Uri does not implement `JsonSerializable`, so `json_encode()` does not call this.
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
        $parsed['host'] = \strtolower($value);
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
        $scheme = null !== $scheme ? \strtolower($scheme) : '';
        if ('' !== $scheme && !\preg_match('/^[a-z][a-z0-9+.\-]*+$/D', $scheme)) {
            throw new \InvalidArgumentException("Invalid scheme '$scheme': it must start with a letter and contain only letters, digits, '+', '-' and '.'");
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
        unset($parsed['user'], $parsed['pass']);
        if ('' !== $user) {
            if (!isset($parsed['host'])) {
                throw new \InvalidArgumentException('Cannot set user info on a URI without a host: call withHost() first');
            }
            $parsed['user'] = static::encode($user, self::USERINFO_UNSAFE);
            if (null !== $password && '' !== $password) {
                $parsed['pass'] = static::encode($password, self::USERINFO_UNSAFE);
            }
        }
        $this->uri = static::buildUriString($parsed);

        return $this;
    }

    /**
     *	Set the port of the url (http/https/rtmp etc)
     *
     * @return $this
     */
    protected function setPort(?int $port)
    {
        if (null !== $port && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException("Invalid port '$port'. Must be between 1 and 65535 or null.");
        }
        $parsed = \parse_url($this->uri);
        if (null !== $port && !isset($parsed['host'])) {
            throw new \InvalidArgumentException('Cannot set a port on a URI without a host: call withHost() first');
        }
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
        $parsed         = \parse_url($this->uri);
        $parsed['path'] = static::encode($path, self::PATH_UNSAFE);
        $this->uri      = static::buildUriString($parsed);

        return $this;
    }

    protected function setFragment(string $value)
    {
        $parsed             = \parse_url($this->uri);
        $parsed['fragment'] = static::encode($value, self::QUERY_UNSAFE);
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
        $parsed['query'] = static::encode($value, self::QUERY_UNSAFE);
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

    protected static function parseQueryString(string $queryString): array
    {
        \parse_str($queryString, $query);

        return $query;
    }

    /**
     * Composes the URI string from `parse_url()` style components (RFC 3986 section 5.3).
     *
     * The authority is written only when there is a host, so the user info and port of a URI without
     * a host are not kept; the port is left out when it is the scheme's default.
     */
    protected static function buildUriString(array $parsed): string
    {
        $result = isset($parsed['scheme']) && '' !== $parsed['scheme'] ? $parsed['scheme'] . ':' : '';
        $path   = $parsed['path'] ?? '';

        if (isset($parsed['host']) && '' !== $parsed['host']) {
            $result .= '//';
            if (isset($parsed['user']) && '' !== $parsed['user']) {
                $result .= $parsed['user'];
                if (isset($parsed['pass']) && '' !== $parsed['pass']) {
                    $result .= ':' . $parsed['pass'];
                }
                $result .= '@';
            }
            $result .= $parsed['host'];
            if (isset($parsed['port']) && (self::SCHEME_PORTS[$parsed['scheme'] ?? ''] ?? null) !== $parsed['port']) {
                $result .= ':' . $parsed['port'];
            }
            if ('' !== $path && '/' !== $path[0]) {
                $path = '/' . $path;
            }
        } elseif (isset($path[1]) && '/' === $path[0] && '/' === $path[1]) {
            // without an authority, "//" would start one
            $path = '/' . \ltrim($path, '/');
        }

        $result .= $path;
        if (isset($parsed['query']) && '' !== $parsed['query']) {
            $result .= '?' . $parsed['query'];
        }
        if (isset($parsed['fragment']) && '' !== $parsed['fragment']) {
            $result .= '#' . $parsed['fragment'];
        }

        return $result;
    }
}
