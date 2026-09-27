<?php

namespace phasync;

use phasync\Internal\PollFlag;

/**
 * A poller on stream_select(), or on phasync-ext's stream_select() when the extension is loaded
 * (no FD_SETSIZE limit there).
 */
final class StreamSelectPoller implements PollerInterface
{
    private const EINTR = 4;

    /**
     * The streams waited for, and their flags, by resource id.
     *
     * @var array<int, resource>
     */
    private array $readStreams = [];

    /**
     * @var array<int, resource>
     */
    private array $writeStreams = [];

    /**
     * @var array<int, PollFlag>
     */
    private array $readFlags = [];

    /**
     * @var array<int, PollFlag>
     */
    private array $writeFlags = [];

    /**
     * Flags not in use: $spareFlags[0 .. $spareCount - 1]. Flags never leave the poller and the
     * event loop, so they are reused. Slots from $spareCount up are stale and never read; they
     * are overwritten, not unset.
     *
     * @var list<PollFlag>
     */
    private array $spareFlags = [];
    private int $spareCount   = 0;

    /** stream_select(), or phasync-ext's when loaded */
    private readonly \Closure $select;

    public function __construct(
        private readonly \Closure $awaitFlag,
        private readonly \Closure $raiseFlag,
    ) {
        $this->select = \function_exists('phasync\ext\stream_select') ? \phasync\ext\stream_select(...) : \stream_select(...);
    }

    public function poll(float $timeout): void
    {
        if (!$this->readStreams && !$this->writeStreams) {
            if ($timeout > 0) {
                \usleep((int) ($timeout * 1000000));
            }

            return;
        }

        $reads   = $this->readStreams;
        $writes  = $this->writeStreams;
        $excepts = [];

        $selectWarning = null;
        \set_error_handler(static function (int $code, string $message) use (&$selectWarning): bool {
            $selectWarning = $message;

            return true;
        });
        try {
            $seconds      = (int) $timeout;
            $microseconds = (int) (($timeout - $seconds) * 1000000);
            $result       = ($this->select)($reads, $writes, $excepts, $seconds, $microseconds);
        } catch (\TypeError|\ValueError $e) {
            // A stream was closed while waited for (ValueError when no open stream is left to
            // select on). Rare, so only now look for it: a closed stream is ready.
            $reads = $writes = [];
            foreach ($this->readStreams as $id => $stream) {
                if (!\is_resource($stream)) {
                    $reads[$id] = $stream;
                }
            }
            foreach ($this->writeStreams as $id => $stream) {
                if (!\is_resource($stream)) {
                    $writes[$id] = $stream;
                }
            }
            if (!$reads && !$writes) {
                throw $e; // not a closed stream, for example one stream_select() can't wait on
            }
            $result = 1;
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
                'stream_select() failed for ' . (\count($this->readFlags) + \count($this->writeFlags)) . ' watched stream(s): '
                . ($selectWarning ?? 'no error was reported')
            );
            $reads  = $this->readFlags;
            $writes = $this->writeFlags;
            foreach ($reads as $flag) {
                $flag->error = $exception;
            }
            foreach ($writes as $flag) {
                $flag->error = $exception;
            }
        }

        $raiseFlag = $this->raiseFlag;
        foreach ($reads as $id => $_) {
            $flag = $this->readFlags[$id];
            unset($this->readStreams[$id], $this->readFlags[$id]);
            $raiseFlag($flag);
        }
        foreach ($writes as $id => $_) {
            $flag = $this->writeFlags[$id];
            unset($this->writeStreams[$id], $this->writeFlags[$id]);
            $raiseFlag($flag);
        }
    }

    public function readable(mixed $stream, float $timeout = \PHP_FLOAT_MAX): void
    {
        $id = \get_resource_id($stream);
        if (isset($this->readFlags[$id])) {
            throw new \LogicException('Another coroutine is already waiting to read from this stream');
        }
        $flag                    = $this->spareCount > 0 ? $this->spareFlags[--$this->spareCount] : new PollFlag();
        $this->readStreams[$id]  = $stream;
        $this->readFlags[$id]    = $flag;
        try {
            ($this->awaitFlag)($flag, $timeout);
        } finally {
            if (($this->readFlags[$id] ?? null) === $flag) {
                // Not raised: the wait was cancelled or timed out
                unset($this->readStreams[$id], $this->readFlags[$id]);
            }
            $this->spareFlags[$this->spareCount++] = $flag;
        }
        if (null !== $flag->error) {
            $error       = $flag->error;
            $flag->error = null;
            throw $error;
        }
    }

    public function writable(mixed $stream, float $timeout = \PHP_FLOAT_MAX): void
    {
        $id = \get_resource_id($stream);
        if (isset($this->writeFlags[$id])) {
            throw new \LogicException('Another coroutine is already waiting to write to this stream');
        }
        $flag                    = $this->spareCount > 0 ? $this->spareFlags[--$this->spareCount] : new PollFlag();
        $this->writeStreams[$id] = $stream;
        $this->writeFlags[$id]   = $flag;
        try {
            ($this->awaitFlag)($flag, $timeout);
        } finally {
            if (($this->writeFlags[$id] ?? null) === $flag) {
                // Not raised: the wait was cancelled or timed out
                unset($this->writeStreams[$id], $this->writeFlags[$id]);
            }
            $this->spareFlags[$this->spareCount++] = $flag;
        }
        if (null !== $flag->error) {
            $error       = $flag->error;
            $flag->error = null;
            throw $error;
        }
    }
}
