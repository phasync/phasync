<?php

namespace phasync;

/**
 * A poller on PHP's stream_select(), used when phasync-ext is not loaded (with it, the loop uses the
 * extension's epoll Poller). Like stream_select() itself it cannot wait on a descriptor numbered
 * FD_SETSIZE (1024 on a typical build) or higher.
 *
 * @internal not part of the public API; may change in any release
 */
final class StreamSelectPoller implements PollerInterface
{
    private const EINTR = 4;

    /**
     * The streams waited for, and the slots their waiters are parked in, by resource id.
     *
     * @var array<int, resource>
     */
    private array $readStreams = [];

    /**
     * @var array<int, resource>
     */
    private array $writeStreams = [];

    /**
     * @var array<int, int>
     */
    private array $readSlots = [];

    /**
     * @var array<int, int>
     */
    private array $writeSlots = [];

    /**
     * How many times stream_select() failed for all waiters at once, and the last such failure.
     * A waiter that sees the count change while it waited throws the failure.
     */
    private int $failures         = 0;
    private ?IOException $failure = null;

    public function __construct(private readonly EventLoop $loop)
    {
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
            $result       = \stream_select($reads, $writes, $excepts, $seconds, $microseconds);
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
            $this->failure = new IOException(
                'stream_select() failed for ' . (\count($this->readSlots) + \count($this->writeSlots)) . ' watched stream(s): '
                . ($selectWarning ?? 'no error was reported')
            );
            ++$this->failures;
            $reads  = $this->readStreams;
            $writes = $this->writeStreams;
        }

        $loop = $this->loop;
        foreach ($reads as $id => $_) {
            $slot = $this->readSlots[$id];
            unset($this->readStreams[$id], $this->readSlots[$id]);
            $loop->unpark($slot); // false if its wait was cancelled or timed out this tick
        }
        foreach ($writes as $id => $_) {
            $slot = $this->writeSlots[$id];
            unset($this->writeStreams[$id], $this->writeSlots[$id]);
            $loop->unpark($slot); // false if its wait was cancelled or timed out this tick
        }
    }

    public function readable(mixed $stream, float $timeout = \PHP_FLOAT_MAX): void
    {
        $id = \get_resource_id($stream);
        if (isset($this->readSlots[$id])) {
            throw new \LogicException('Another coroutine is already waiting to read from this stream');
        }
        $slot                   = $this->loop->getSlot();
        $this->readStreams[$id] = $stream;
        $this->readSlots[$id]   = $slot;
        $failures               = $this->failures;
        try {
            $this->loop->park($slot, $timeout);
        } finally {
            if (($this->readSlots[$id] ?? null) === $slot) {
                // Not unparked: the wait was cancelled or timed out
                unset($this->readStreams[$id], $this->readSlots[$id]);
            }
        }
        if ($failures !== $this->failures) {
            throw $this->failure;
        }
    }

    public function writable(mixed $stream, float $timeout = \PHP_FLOAT_MAX): void
    {
        $id = \get_resource_id($stream);
        if (isset($this->writeSlots[$id])) {
            throw new \LogicException('Another coroutine is already waiting to write to this stream');
        }
        $slot                    = $this->loop->getSlot();
        $this->writeStreams[$id] = $stream;
        $this->writeSlots[$id]   = $slot;
        $failures                = $this->failures;
        try {
            $this->loop->park($slot, $timeout);
        } finally {
            if (($this->writeSlots[$id] ?? null) === $slot) {
                // Not unparked: the wait was cancelled or timed out
                unset($this->writeStreams[$id], $this->writeSlots[$id]);
            }
        }
        if ($failures !== $this->failures) {
            throw $this->failure;
        }
    }
}
