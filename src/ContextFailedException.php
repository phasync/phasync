<?php
namespace phasync;

use RuntimeException;
use Throwable;

final class ContextFailedException extends RuntimeException {
    public function __construct(Throwable $exception) {
        parent::__construct("The context failed due to an unhandled exception", 0, $exception);
    }
}