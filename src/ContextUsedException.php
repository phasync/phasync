<?php
namespace phasync;

use RuntimeException;

class ContextUsedException extends RuntimeException {

    public function __construct() {
        parent::__construct("Can't use a context multiple times");
    }
}
