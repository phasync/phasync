<?php
namespace phasync;

class DriverFactory {
    public static function createDriver(): DriverInterface {
        return new Drivers\StreamSelectDriver();
    }
}