<?php

use phasync\Psr\Uri;

$uri = new Uri('https://ann:secret@example.com:8443/docs/guide?page=2#install');

echo 'scheme:    ', $uri->getScheme(), "\n";
echo 'authority: ', $uri->getAuthority(), "\n";
echo 'user info: ', $uri->getUserInfo(), "\n";
echo 'host:      ', $uri->getHost(), "\n";
echo 'port:      ', $uri->getPort(), "\n";
echo 'path:      ', $uri->getPath(), "\n";
echo 'query:     ', $uri->getQuery(), "\n";
echo 'fragment:  ', $uri->getFragment(), "\n";

echo $uri->withScheme('http')->withPort(null)->withUserInfo('')->withFragment(''), "\n";
var_dump((new Uri('https://example.com:443/'))->getPort());   // the scheme's default port: null
