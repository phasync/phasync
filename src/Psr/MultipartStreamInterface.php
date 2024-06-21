<?php

namespace phasync\Psr;

use Psr\Http\Message\StreamInterface;

interface MultipartStreamInterface extends StreamInterface
{
    public function getContentType(): string;
}
