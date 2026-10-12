<?php

use phasync\Psr\Request;

$request = Request::create('GET', 'https://api.example.com/v1/users?page=2');

echo $request->getRequestTarget(), "\n";
echo $request->getHeaderLine('Host'), "\n";
echo $request->getUri(), "\n";
