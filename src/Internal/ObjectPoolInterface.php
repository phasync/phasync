<?php
namespace phasync\Internal;

interface ObjectPoolInterface {
    public static function clearState(object $instance): void;
}