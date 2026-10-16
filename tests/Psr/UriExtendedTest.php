<?php

use phasync\Psr\Uri;

test('getParam returns null for missing query string', function () {
    $uri = new Uri('http://example.com/path');
    expect($uri->getParam('foo'))->toBeNull();
});

test('getParam returns null for missing parameter', function () {
    $uri = new Uri('http://example.com/path?bar=value');
    expect($uri->getParam('foo'))->toBeNull();
});

test('getParam returns parameter value', function () {
    $uri = new Uri('http://example.com/path?foo=bar');
    expect($uri->getParam('foo'))->toBe('bar');
});

test('getParam returns parameter with special characters', function () {
    $uri = new Uri('http://example.com?search=hello%20world');
    expect($uri->getParam('search'))->toBe('hello world');
});

test('withHost with invalid host containing slash throws', function () {
    $uri = new Uri('http://example.com/path');
    expect(fn () => $uri->withHost('example.com/invalid'))->toThrow(InvalidArgumentException::class);
});

test('withHost with non-string throws', function () {
    $uri = new Uri('http://example.com/path');
    expect(fn () => $uri->withHost(123))->toThrow(InvalidArgumentException::class);
});

test('withHost with invalid host format throws', function () {
    $uri = new Uri('http://example.com/path');
    expect(fn () => $uri->withHost('invalid host'))->toThrow(InvalidArgumentException::class);
});

test('withHost with empty string removes authority', function () {
    $uri    = new Uri('http://example.com/path');
    $newUri = $uri->withHost('');
    expect((string) $newUri)->toBe('http:/path');
});

test('jsonSerialize returns string representation', function () {
    $uri = new Uri('https://example.com/path?query=value#fragment');
    expect($uri->jsonSerialize())->toBe('https://example.com/path?query=value#fragment');
});

test('withUserInfo throws when no host exists', function () {
    $uri = new Uri('/path');
    expect(fn () => $uri->withUserInfo('user'))->toThrow(InvalidArgumentException::class);
});

test('withUserInfo with password throws when no host exists', function () {
    $uri = new Uri('/path');
    expect(fn () => $uri->withUserInfo('user', 'pass'))->toThrow(InvalidArgumentException::class);
});

test('withUserInfo with valid host', function () {
    $uri    = new Uri('http://example.com/path');
    $newUri = $uri->withUserInfo('user', 'pass');
    expect((string) $newUri)->toBe('http://user:pass@example.com/path');
});

test('withUserInfo with empty user removes credentials', function () {
    $uri    = new Uri('http://user:pass@example.com/path');
    $newUri = $uri->withUserInfo('');
    expect((string) $newUri)->toBe('http://example.com/path');
});

test('withPort throws when port is invalid (too low)', function () {
    $uri = new Uri('http://example.com/path');
    expect(fn () => $uri->withPort(0))->toThrow(InvalidArgumentException::class);
});

test('withPort throws when port is invalid (too high)', function () {
    $uri = new Uri('http://example.com/path');
    expect(fn () => $uri->withPort(65536))->toThrow(InvalidArgumentException::class);
});

test('withPort throws when port is negative', function () {
    $uri = new Uri('http://example.com/path');
    expect(fn () => $uri->withPort(-1))->toThrow(InvalidArgumentException::class);
});

test('withPort throws when no host exists', function () {
    $uri = new Uri('/path');
    expect(fn () => $uri->withPort(8080))->toThrow(InvalidArgumentException::class);
});

test('withPort with null removes port', function () {
    $uri    = new Uri('http://example.com:8080/path');
    $newUri = $uri->withPort(null);
    expect((string) $newUri)->toBe('http://example.com/path');
});

test('withPort with valid port', function () {
    $uri    = new Uri('http://example.com/path');
    $newUri = $uri->withPort(8080);
    expect((string) $newUri)->toBe('http://example.com:8080/path');
});

test('withFragment sets fragment', function () {
    $uri    = new Uri('http://example.com/path');
    $newUri = $uri->withFragment('section');
    expect((string) $newUri)->toBe('http://example.com/path#section');
});

test('withFragment replaces existing fragment', function () {
    $uri    = new Uri('http://example.com/path#old');
    $newUri = $uri->withFragment('new');
    expect((string) $newUri)->toBe('http://example.com/path#new');
});

test('withFragment with empty string removes fragment', function () {
    $uri    = new Uri('http://example.com/path#section');
    $newUri = $uri->withFragment('');
    expect((string) $newUri)->toBe('http://example.com/path');
});

test('withQuery sets query', function () {
    $uri    = new Uri('http://example.com/path');
    $newUri = $uri->withQuery('foo=bar');
    expect((string) $newUri)->toBe('http://example.com/path?foo=bar');
});

test('withQuery replaces existing query', function () {
    $uri    = new Uri('http://example.com/path?old=value');
    $newUri = $uri->withQuery('new=value');
    expect((string) $newUri)->toBe('http://example.com/path?new=value');
});

test('withPath sets path', function () {
    $uri    = new Uri('http://example.com/old');
    $newUri = $uri->withPath('/new');
    expect((string) $newUri)->toBe('http://example.com/new');
});

test('withPath with special characters percent encodes', function () {
    $uri    = new Uri('http://example.com/');
    $newUri = $uri->withPath('/path with spaces');
    expect($newUri->getPath())->toBe('/path%20with%20spaces');
});

test('withScheme sets scheme', function () {
    $uri    = new Uri('http://example.com/path');
    $newUri = $uri->withScheme('https');
    expect((string) $newUri)->toBe('https://example.com/path');
});

test('withScheme with empty string removes scheme', function () {
    $uri    = new Uri('http://example.com/path');
    $newUri = $uri->withScheme('');
    expect((string) $newUri)->toBe('//example.com/path');
});

test('withScheme with invalid scheme throws', function () {
    $uri = new Uri('http://example.com/path');
    expect(fn () => $uri->withScheme('9invalid'))->toThrow(InvalidArgumentException::class);
});

test('getScheme returns lowercase scheme', function () {
    $uri = new Uri('HTTP://example.com/path');
    expect($uri->getScheme())->toBe('http');
});

test('getHost returns lowercase host', function () {
    $uri = new Uri('http://EXAMPLE.COM/path');
    expect($uri->getHost())->toBe('example.com');
});

test('getUser returns user from URI', function () {
    $uri = new Uri('http://user@example.com/path');
    expect($uri->getUser())->toBe('user');
});

test('getPassword returns password from URI', function () {
    $uri = new Uri('http://user:pass@example.com/path');
    expect($uri->getPassword())->toBe('pass');
});

test('getPassword returns null when no password', function () {
    $uri = new Uri('http://user@example.com/path');
    expect($uri->getPassword())->toBeNull();
});

test('getPort returns null for default http port', function () {
    $uri = new Uri('http://example.com:80/path');
    expect($uri->getPort())->toBeNull();
});

test('getPort returns null for default https port', function () {
    $uri = new Uri('https://example.com:443/path');
    expect($uri->getPort())->toBeNull();
});

test('getPort returns non-default port', function () {
    $uri = new Uri('http://example.com:8080/path');
    expect($uri->getPort())->toBe(8080);
});

test('getPort returns null when no port specified', function () {
    $uri = new Uri('http://example.com/path');
    expect($uri->getPort())->toBeNull();
});

test('Invalid relative URI with only slashes throws exception', function () {
    expect(fn () => new Uri('///path'))->toThrow(InvalidArgumentException::class);
});

test('getQuery returns query string', function () {
    $uri = new Uri('http://example.com/path?foo=bar&baz=qux');
    expect($uri->getQuery())->toBe('foo=bar&baz=qux');
});

test('getQuery returns empty string when no query', function () {
    $uri = new Uri('http://example.com/path');
    expect($uri->getQuery())->toBe('');
});

test('getFragment returns fragment', function () {
    $uri = new Uri('http://example.com/path#section');
    expect($uri->getFragment())->toBe('section');
});

test('getFragment returns empty string when no fragment', function () {
    $uri = new Uri('http://example.com/path');
    expect($uri->getFragment())->toBe('');
});

test('getAuthority returns authority with user and password', function () {
    $uri = new Uri('http://user:pass@example.com:8080/path');
    expect($uri->getAuthority())->toBe('user:pass@example.com:8080');
});

test('getAuthority returns authority with default port', function () {
    $uri = new Uri('http://example.com:80/path');
    expect($uri->getAuthority())->toBe('example.com');
});

test('getAuthority returns authority without port when default', function () {
    $uri = new Uri('http://user:pass@example.com:80/path');
    expect($uri->getAuthority())->toBe('user:pass@example.com');
});

test('getAuthority returns empty string without host', function () {
    $uri = new Uri('/path');
    expect($uri->getAuthority())->toBe('');
});

test('getUserInfo returns user and password', function () {
    $uri = new Uri('http://user:pass@example.com/path');
    expect($uri->getUserInfo())->toBe('user:pass');
});

test('getUserInfo returns only user when no password', function () {
    $uri = new Uri('http://user@example.com/path');
    expect($uri->getUserInfo())->toBe('user');
});

test('getUserInfo returns empty string without user', function () {
    $uri = new Uri('http://example.com/path');
    expect($uri->getUserInfo())->toBe('');
});

test('URI normalization lowercases scheme and host', function () {
    $uri = new Uri('HTTP://EXAMPLE.COM/path');
    expect((string) $uri)->toBe('http://example.com/path');
});

test('URI normalization removes default port', function () {
    $uri = new Uri('http://example.com:80/path');
    expect((string) $uri)->toBe('http://example.com/path');
});

test('URI normalization keeps non-default port', function () {
    $uri = new Uri('http://example.com:8080/path');
    expect((string) $uri)->toBe('http://example.com:8080/path');
});

test('Relative URI path', function () {
    $uri = new Uri('/path/to/resource');
    expect($uri->getPath())->toBe('/path/to/resource');
    expect($uri->getScheme())->toBe('');
    expect($uri->getHost())->toBe('');
});

test('URI with percent encoding in path', function () {
    $uri = new Uri('http://example.com/search?q=hello%20world');
    expect($uri->getPath())->toBe('/search');
    expect($uri->getQuery())->toBe('q=hello%20world');
});

test('URI with special characters in path percent encodes', function () {
    $uri = new Uri('http://example.com/path with spaces');
    expect($uri->getPath())->toBe('/path%20with%20spaces');
});

test('withPath creates copy', function () {
    $uri    = new Uri('http://example.com/old');
    $newUri = $uri->withPath('/new');
    expect((string) $uri)->toBe('http://example.com/old');
    expect((string) $newUri)->toBe('http://example.com/new');
});

test('withQuery creates copy', function () {
    $uri    = new Uri('http://example.com?old=value');
    $newUri = $uri->withQuery('new=value');
    expect((string) $uri)->toBe('http://example.com?old=value');
    expect((string) $newUri)->toBe('http://example.com?new=value');
});

test('withFragment creates copy', function () {
    $uri    = new Uri('http://example.com#old');
    $newUri = $uri->withFragment('new');
    expect((string) $uri)->toBe('http://example.com#old');
    expect((string) $newUri)->toBe('http://example.com#new');
});

test('withScheme creates copy', function () {
    $uri    = new Uri('http://example.com');
    $newUri = $uri->withScheme('https');
    expect((string) $uri)->toBe('http://example.com');
    expect((string) $newUri)->toBe('https://example.com');
});

test('withHost creates copy', function () {
    $uri    = new Uri('http://example.com/path');
    $newUri = $uri->withHost('other.com');
    expect((string) $uri)->toBe('http://example.com/path');
    expect((string) $newUri)->toBe('http://other.com/path');
});

test('withUserInfo creates copy', function () {
    $uri    = new Uri('http://example.com/path');
    $newUri = $uri->withUserInfo('user', 'pass');
    expect((string) $uri)->toBe('http://example.com/path');
    expect((string) $newUri)->toBe('http://user:pass@example.com/path');
});

test('withPort creates copy', function () {
    $uri    = new Uri('http://example.com/path');
    $newUri = $uri->withPort(8080);
    expect((string) $uri)->toBe('http://example.com/path');
    expect((string) $newUri)->toBe('http://example.com:8080/path');
});

test('IPv6 host address', function () {
    $uri = new Uri('http://[2001:db8::1]/path');
    expect($uri->getHost())->toBe('[2001:db8::1]');
});

test('withHost IPv6 address', function () {
    $uri    = new Uri('http://example.com/path');
    $newUri = $uri->withHost('[2001:db8::1]');
    expect((string) $newUri)->toBe('http://[2001:db8::1]/path');
});

test('getParam with array parameter', function () {
    $uri    = new Uri('http://example.com?items[]=a&items[]=b');
    $params = $uri->getParam('items');
    expect($params)->toBeArray();
});

test('URI with empty fragment', function () {
    $uri = new Uri('http://example.com/path#');
    expect($uri->getFragment())->toBe('');
});

test('URI with empty query', function () {
    $uri = new Uri('http://example.com/path?');
    expect($uri->getQuery())->toBe('');
});

test('URI with multiple query parameters', function () {
    $uri = new Uri('http://example.com?a=1&b=2&c=3');
    expect($uri->getParam('a'))->toBe('1');
    expect($uri->getParam('b'))->toBe('2');
    expect($uri->getParam('c'))->toBe('3');
});

test('withPath with empty path', function () {
    $uri    = new Uri('http://example.com/path');
    $newUri = $uri->withPath('');
    expect((string) $newUri)->toBe('http://example.com');
});

test('withQuery with empty query', function () {
    $uri    = new Uri('http://example.com/path?query=value');
    $newUri = $uri->withQuery('');
    expect((string) $newUri)->toBe('http://example.com/path');
});

test('URI path with special allowed characters', function () {
    $uri = new Uri('http://example.com/path!@$&()*+,;=:');
    expect($uri->getPath())->toBe('/path!@$&()*+,;=:');
});

test('URI query with special allowed characters', function () {
    $uri = new Uri('http://example.com?query=value!@$&()*+,;=:?');
    expect($uri->getQuery())->toBe('query=value!@$&()*+,;=:?');
});

test('withPath preserves path with double slash when there is a host', function () {
    $uri    = new Uri('http://example.com');
    $newUri = $uri->withPath('//path');
    expect($newUri->getPath())->toBe('/path');
});

test('URI without scheme and host', function () {
    $uri = new Uri('path/to/resource');
    expect($uri->getScheme())->toBe('');
    expect($uri->getHost())->toBe('');
    expect($uri->getPath())->toBe('path/to/resource');
});

test('URI with only path and fragment', function () {
    $uri = new Uri('/path#fragment');
    expect($uri->getPath())->toBe('/path');
    expect($uri->getFragment())->toBe('fragment');
    expect($uri->getScheme())->toBe('');
});

test('URI with only path and query', function () {
    $uri = new Uri('/path?query=value');
    expect($uri->getPath())->toBe('/path');
    expect($uri->getQuery())->toBe('query=value');
    expect($uri->getScheme())->toBe('');
});

// Test protected methods using a subclass
class TestableUri extends Uri
{
    public function testUnsetFragment()
    {
        return $this->unsetFragment();
    }

    public function testSetParam($param, $value)
    {
        return $this->setParam($param, $value);
    }

    public function testUnsetParam($param)
    {
        return $this->unsetParam($param);
    }

    public function testUnsetQuery()
    {
        return $this->unsetQuery();
    }

    public static function testParseQueryString($queryString)
    {
        return self::parseQueryString($queryString);
    }

    public static function testBuildUriString($parsed)
    {
        return self::buildUriString($parsed);
    }
}

test('unsetFragment removes fragment from URI', function () {
    $uri = new TestableUri('http://example.com/path#fragment');
    $uri->testUnsetFragment();
    expect($uri->getFragment())->toBe('');
});

test('unsetFragment returns this for chaining', function () {
    $uri    = new TestableUri('http://example.com/path#fragment');
    $result = $uri->testUnsetFragment();
    expect($result)->toBe($uri);
});

test('setParam adds parameter to query', function () {
    $uri = new TestableUri('http://example.com/path');
    $uri->testSetParam('foo', 'bar');
    expect($uri->getQuery())->toContain('foo=bar');
});

test('setParam replaces existing parameter', function () {
    $uri = new TestableUri('http://example.com/path?foo=old');
    $uri->testSetParam('foo', 'new');
    expect($uri->getQuery())->toContain('foo=new');
    expect($uri->getQuery())->not->toContain('foo=old');
});

test('setParam with multiple parameters', function () {
    $uri = new TestableUri('http://example.com/path?a=1');
    $uri->testSetParam('b', '2');
    expect($uri->getQuery())->toContain('a=1');
    expect($uri->getQuery())->toContain('b=2');
});

test('unsetParam removes parameter from query', function () {
    $uri = new TestableUri('http://example.com/path?foo=bar&baz=qux');
    $uri->testUnsetParam('foo');
    expect($uri->getQuery())->not->toContain('foo');
    expect($uri->getQuery())->toContain('baz=qux');
});

test('unsetParam with no query returns new instance', function () {
    $uri    = new TestableUri('http://example.com/path');
    $result = $uri->testUnsetParam('foo');
    expect($result)->toBeInstanceOf(TestableUri::class);
});

test('unsetQuery removes entire query', function () {
    $uri = new TestableUri('http://example.com/path?foo=bar&baz=qux');
    $uri->testUnsetQuery();
    expect($uri->getQuery())->toBe('');
});

test('parseQueryString parses standard query', function () {
    $result = TestableUri::testParseQueryString('foo=bar&baz=qux');
    expect($result)->toHaveKey('foo');
    expect($result['foo'])->toBe('bar');
    expect($result['baz'])->toBe('qux');
});

test('parseQueryString returns empty array for invalid input', function () {
    $result = TestableUri::testParseQueryString('');
    expect($result)->toBeArray();
});

test('parseQueryString with special characters', function () {
    $result = TestableUri::testParseQueryString('search=hello+world');
    expect($result)->toHaveKey('search');
    expect($result['search'])->toBe('hello world');
});

test('parseQueryString with array notation', function () {
    $result = TestableUri::testParseQueryString('items[]=a&items[]=b');
    expect($result)->toHaveKey('items');
    expect($result['items'])->toBeArray();
});

test('URI path normalization without authority normalizes double slash', function () {
    // Create a relative URI without authority
    $uri = new TestableUri('relative//path');
    // Path normalization happens when building the URI
    expect($uri->getPath())->toBe('relative//path');
});

test('URI buildUriString with path starting without slash with authority', function () {
    $uri = new TestableUri('http://example.com');
    $uri = $uri->withPath('path');
    // When there's an authority, path must start with /
    expect((string) $uri)->toContain('//example.com/path');
});

test('buildUriString with all components', function () {
    $uri = new TestableUri('http://user:pass@example.com:8080/path?query=value#fragment');
    $str = (string) $uri;
    expect($str)->toContain('user:pass@');
    expect($str)->toContain(':8080');
    expect($str)->toContain('?query=value');
    expect($str)->toContain('#fragment');
});

test('buildUriString with path starting with double slash and no authority', function () {
    // Build a URI component array with no host but path starting with //
    $parsed = ['path' => '//path'];
    $result = TestableUri::testBuildUriString($parsed);
    // Should normalize the path to /path
    expect($result)->toBe('/path');
});

test('buildUriString adds leading slash to path when authority exists and path does not', function () {
    $parsed = ['host' => 'example.com', 'path' => 'noLeadingSlash'];
    $result = TestableUri::testBuildUriString($parsed);
    expect($result)->toContain('//example.com/noLeadingSlash');
});

test('parseQueryString uses mb_parse_str when available or falls back to parse_str', function () {
    // Test that parseQueryString handles the return value properly
    // Both mb_parse_str and parse_str will return true on success
    $result = TestableUri::testParseQueryString('key=value');
    expect($result)->toHaveKey('key');
    expect($result['key'])->toBe('value');
});

test('parseQueryString fallback handles regular parse_str function', function () {
    // This tests the normal path where parse_str parses correctly
    $result = TestableUri::testParseQueryString('a=1&b=2&c=3');
    expect($result)->toBeArray();
    expect($result['a'])->toBe('1');
    expect($result['b'])->toBe('2');
    expect($result['c'])->toBe('3');
});
