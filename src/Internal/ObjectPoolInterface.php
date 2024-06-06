<?php

namespace phasync\Internal;

interface ObjectPoolInterface
{
    public function returnToPool(): void;
}
