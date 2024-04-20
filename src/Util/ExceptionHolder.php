<?php
namespace phasync\Util;

use Closure;
use Fiber;
use phasync\Loop;
use Throwable;

final class ExceptionHolder {
    private bool $fetched = false;
    private ?Throwable $exception;
    private ?Closure $handler;

    public function __construct(Throwable $exception, ?Closure $handler) {
        $this->exception = $exception;
        $this->handler = $handler;
    }

    public function get(): Throwable {
        $this->fetched = true;
        $this->handler = null;
        return $this->exception;
    }
    
    public function __destruct() {
        if (!$this->fetched) {
            $exception = $this->exception;
            $this->exception = null;
            if ($this->handler) {
                ($this->handler)($exception);
            } else {
                Loop::handleException($exception);
            }
        }
    }
}