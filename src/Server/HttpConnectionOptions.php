<?php
namespace phasync\Server;

use Charm\AbstractOptions;

class HttpConnectionOptions extends AbstractOptions {

    /**
     * Max memory buffer size
     */
    public ?float $memoryBufferSize = null;
}