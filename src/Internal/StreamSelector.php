<?php

namespace phasync\Internal;

use phasync;
use phasync\SelectorInterface;
use phasync\TimeoutException;

/**
 * This class can be used with stream resources to become selectable
 * with phasync::select(). Use the read or write arguments of phasync::select()
 * instead of this class directly.
 *
 * @internal
 */
final class StreamSelector implements SelectorInterface, ObjectPoolInterface
{
    use ObjectPoolTrait;
    use SelectableTrait;

    private int $id;
    private int $timestamp;
    private mixed $resource;
    private int $mode;
    private bool $isBlocking;

    public static function create(mixed $resource, int $mode): StreamSelector
    {
        $instance = self::popInstance() ?? new self();

        $instance->timestamp  = \hrtime(true);
        $instance->resource   = $resource;
        $instance->mode       = $mode;
        $instance->isBlocking = 0 === (\phasync::streamPoll($resource) & $instance->mode);

        if ($instance->isBlocking) {
            \phasync::go(args: [$instance], fn: static function (StreamSelector $instance) {
                $timestamp = $instance->timestamp;
                while ($instance->timestamp === $timestamp && \is_resource($instance->resource) && 'stream' === \get_resource_type($instance->resource)) {
                    try {
                        \phasync::stream($instance->resource, $instance->mode, 10);
                        if ($instance->timestamp === $timestamp) {
                            $instance->isBlocking = false;
                            $instance->getSelectManager()->notify();
                        }
                    } catch (TimeoutException) {
                        // Ignored, we'll handle cleanup ourself
                    }
                }
            });
        }

        return $instance;
    }

    private function __construct()
    {
        $this->id = \spl_object_id($this);
    }

    public function getSelected(): mixed
    {
        return $this->resource;
    }

    public function returnToPool(): void
    {
        $this->resource                     = null;
        $this->timestamp                    = \hrtime(true);
        self::$pool[self::$instanceCount++] = $this;
    }

    public function selectWillBlock(): bool
    {
        return $this->isBlocking;
    }
}
