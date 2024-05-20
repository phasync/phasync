<?php
namespace phasync\Process;

interface ProcessInterface {

    const STDIN = 0;
    const STDOUT = 1;
    const STDERR = 2;

    public function stop(): bool;
    public function isRunning(): bool;
    public function isStopped(): bool;
    public function getExitCode(): int|false;
    public function sendSignal(int $signal=15): bool;
    public function read(int $fd=self::STDOUT): string|false;
    public function write(string $data, int $fd=self::STDIN): int|false;
}
