<?php
namespace phasync\Internal;

use Fiber;
use LogicException;
use phasync\Debug;
use Throwable;
use WeakMap;

final class FiberState {

    private static ?WeakMap $fibers = null;

    public static function for(Fiber $fiber): FiberState {
        if (self::$fibers === null) {
            self::$fibers = new WeakMap();
        }
        if (!isset(self::$fibers[$fiber])) {
            throw new LogicException("Fiber not registered");
        }
        return self::$fibers[$fiber];
    }

    public static function register(Fiber $fiber): void {
        if (self::$fibers === null) {
            self::$fibers = new WeakMap();
        }
        self::$fibers[$fiber] = new FiberState($fiber);
    }

    public string $fiberInfo;
    public string $location;
    public array $createdStack;
    public array $log = [];

    private function __construct(Fiber $fiber) {
        $this->fiberInfo = Debug::getDebugInfo($fiber);
        $this->createdStack = \debug_backtrace();
        \array_shift($this->createdStack);
        \array_shift($this->createdStack);
        \array_shift($this->createdStack);
    }

    public function __destruct() {
        $this->log[] = [ \microtime(true), 'destroyed' ];
        $this->dumpLog();
    }

    public function dumpLog(): void {
        $path = [];
        foreach ($this->createdStack as $trace) {
            if (isset($trace['file']))
                $path[] = $trace['file'] . '(' . $trace['line'] . ')';
        }
        fwrite(\STDERR, $this->fiberInfo . "\n");
        fwrite(\STDERR, " >> " . implode(" ", $path) . "\n");
        $firstTime = null;
        foreach ($this->log as $log) {
            if ($firstTime === null) {
                $firstTime = $log[0];
            }
            fwrite(\STDERR, " - " . \number_format($log[0]-$firstTime, 3) . " " . \trim($log[1]) . "\n");
        }
    }

    public function log(string|Throwable $message): void {
        $this->log[] = [ \microtime(true), $message ];
    }

}
