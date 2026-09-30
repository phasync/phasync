<?php

namespace phasync\Util;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * A PSR-3 logger writing Console::log() lines: to a terminal styled, piped plain.
 *
 *     $logger = new ConsoleLogger();                                  // STDERR, every level
 *     $logger = new ConsoleLogger(new Console(STDOUT), 'warning');    // warning and up
 *
 * It satisfies psr/log 1, 2 and 3 alike: untyped parameters where earlier versions have them,
 * `void` returns as 3 requires.
 */
final class ConsoleLogger implements LoggerInterface
{
    private const RANK = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7];

    private readonly Console $console;
    private readonly int $minimum;

    /**
     * @param Console|null $console where the lines go; null: STDERR
     * @param string       $level   the lowest level written
     * @param string       $source  a column after the time, such as a worker number
     */
    public function __construct(?Console $console = null, string $level = 'debug', private readonly string $source = '')
    {
        if (!isset(self::RANK[$level])) {
            throw new InvalidArgumentException("Unknown log level '$level'");
        }
        $this->console = $console ?? new Console(\STDERR);
        $this->minimum = self::RANK[$level];
    }

    public function log($level, $message, array $context = []): void
    {
        if (!isset(self::RANK[$level])) {
            throw new InvalidArgumentException("Unknown log level '$level'");
        }
        if (self::RANK[$level] >= $this->minimum) {
            $this->console->log($level, $message instanceof \Stringable ? $message : (string) $message, $context, $this->source);
        }
    }

    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
}
