<?php

require \realpath(__DIR__ . '/../../../vendor/autoload.php') ?: \realpath(__DIR__ . '/../vendor/autoload.php') ?: 'vendor/autoload.php';

\file_put_contents(__DIR__ . '/../HELLO', 'WORLD');

$input    = \file_get_contents('php://input');
$callable = \unserialize($input);

try {
    $result = $callable();
    echo \serialize($result);
} catch (Throwable $e) {
    echo \serialize($e);
}
