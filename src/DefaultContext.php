<?php
namespace phasync;

use WeakMap;

class DefaultContext implements ContextInterface {

    private readonly WeakMap $fibers;

    public function __construct() {
        $this->fibers = new WeakMap();
    }

    public function getFibers(): WeakMap {
        return $this->fibers;
    }
}