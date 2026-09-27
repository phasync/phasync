<?php

namespace phasync;

/**
 * A poller on stream_select(), or on phasync-ext's stream_select() when the extension is loaded
 * (no FD_SETSIZE limit there).
 */
final class StreamSelectPoller implements PollerInterface
{
    private const EINTR = 4;

    /**
     * The streams waited for and their flags, by direction (0: read, 1: write) and resource id.
     *
     * @var array{0: array<int, resource>, 1: array<int, resource>}
     */
    private array $streams = [[], []];

    /**
     * @var array{0: array<int, \stdClass>, 1: array<int, \stdClass>}
     */
    private array $flags = [[], []];

    private readonly bool $useExtSelect;

    public function __construct(
        private readonly \Closure $awaitFlag,
        private readonly \Closure $raiseFlag,
    ) {
        $this->useExtSelect = \function_exists('phasync\ext\stream_select');
    }

    public function poll(float $timeout): void
    {
        if (!$this->streams[0] && !$this->streams[1]) {
            if ($timeout > 0) {
                \usleep((int) ($timeout * 1000000));
            }

            return;
        }

        [$reads, $writes] = $this->streams;
        $excepts          = [];

        $selectWarning = null;
        \set_error_handler(static function (int $code, string $message) use (&$selectWarning): bool {
            $selectWarning = $message;

            return true;
        });
        try {
            $seconds      = (int) $timeout;
            $microseconds = (int) (($timeout - $seconds) * 1000000);
            $result       = $this->useExtSelect
                ? \phasync\ext\stream_select($reads, $writes, $excepts, $seconds, $microseconds)
                : \stream_select($reads, $writes, $excepts, $seconds, $microseconds);
        } catch (\TypeError|\ValueError $e) {
            // A stream was closed while waited for (ValueError when no open stream is left to
            // select on). Rare, so only now look for it.
            $closed = [];
            foreach ($this->streams as $direction => $streams) {
                foreach ($streams as $id => $stream) {
                    if (!\is_resource($stream)) {
                        $closed[] = [$direction, $id];
                    }
                }
            }
            if (!$closed) {
                throw $e; // not a closed stream, for example one stream_select() can't wait on
            }
            foreach ($closed as [$direction, $id]) {
                $this->ready($direction, $id);
            }

            return;
        } finally {
            \restore_error_handler();
        }

        if (false === $result) {
            if (\str_contains($selectWarning ?? '', '[' . self::EINTR . ']')) {
                // A signal interrupted the wait. Nothing is ready yet; the waiters keep waiting.
                return;
            }
            // stream_select() failed for the whole batch, not just one stream -- for example
            // a stream's file descriptor number is >= FD_SETSIZE (1024 on a typical POSIX
            // build). Every waiter must be told, loudly, or it would wait forever with no trace
            // of why.
            $exception = new IOException(
                'stream_select() failed for ' . (\count($this->flags[0]) + \count($this->flags[1])) . ' watched stream(s): '
                . ($selectWarning ?? 'no error was reported')
            );
            foreach ($this->flags as $direction => $flags) {
                foreach ($flags as $id => $flag) {
                    $flag->error = $exception;
                    $this->ready($direction, $id);
                }
            }

            return;
        }

        foreach ($reads as $id => $_) {
            $this->ready(0, $id);
        }
        foreach ($writes as $id => $_) {
            $this->ready(1, $id);
        }
    }

    public function readable(mixed $stream, float $timeout = \PHP_FLOAT_MAX): void
    {
        $this->wait(0, $stream, $timeout);
    }

    public function writable(mixed $stream, float $timeout = \PHP_FLOAT_MAX): void
    {
        $this->wait(1, $stream, $timeout);
    }

    private function wait(int $direction, mixed $stream, float $timeout): void
    {
        $id = \get_resource_id($stream);
        if (isset($this->flags[$direction][$id])) {
            throw new \LogicException('Another coroutine is already waiting to ' . ($direction ? 'write to' : 'read from') . ' this stream');
        }
        $flag                           = new \stdClass();
        $this->streams[$direction][$id] = $stream;
        $this->flags[$direction][$id]   = $flag;
        try {
            ($this->awaitFlag)($flag, $timeout);
        } finally {
            if (($this->flags[$direction][$id] ?? null) === $flag) {
                // Not raised: the wait was cancelled or timed out
                unset($this->streams[$direction][$id], $this->flags[$direction][$id]);
            }
        }
        if (isset($flag->error)) {
            throw $flag->error;
        }
    }

    private function ready(int $direction, int $id): void
    {
        $flag = $this->flags[$direction][$id];
        unset($this->streams[$direction][$id], $this->flags[$direction][$id]);
        ($this->raiseFlag)($flag);
    }
}
