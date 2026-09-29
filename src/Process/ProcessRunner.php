<?php

namespace phasync\Process;

/**
 * Launches and manages a child process on POSIX and Windows alike.
 *
 * STDIN/STDOUT/STDERR are `['socket']` descriptors (proc_open() has supported these since PHP
 * 8.0, php-src 547d98b / GH-5777), not `['pipe', ...]`. A pipe cannot be made non-blocking or
 * polled by `stream_select()`/`WaitForMultipleObjects` on Windows; a socket can, on every
 * platform phasync supports (>= 8.2). Using sockets everywhere, instead of pipes on POSIX and
 * something else on Windows, keeps this one implementation and one behaviour: no per-platform
 * branch anywhere below this class except command resolution and `proc_open()`'s own
 * Windows-only `bypass_shell` option.
 *
 * Measured/considered behaviour differences of a socketpair vs. a pipe, for the child:
 * - EOF on close: identical. Closing the parent's end of either a pipe or a socketpair delivers
 *   EOF (a zero-length read) to the child, and vice versa.
 * - SIGPIPE/EPIPE: identical on POSIX. A child that writes after the parent has closed its end
 *   gets SIGPIPE (default action: terminate) exactly as it would writing to a closed pipe; the
 *   kernel raises SIGPIPE for a broken connection on a stream socket the same way it does for a
 *   pipe. Nothing here changes a child's signal disposition.
 * - `fstat()`/`isatty()` on the child's fd: a socket reports `S_ISSOCK`, a pipe `S_ISFIFO` --
 *   neither is a tty, so libc's default (fully buffered, non-interactive) stdio buffering is the
 *   same either way. A program that specifically branches on `S_ISFIFO` (rare; e.g. to decide
 *   whether `splice()`/`sendfile()` applies) would see a difference, but none of phasync's own
 *   code or tests do this, and it is not a documented assumption of `ProcessInterface`.
 */
final class ProcessRunner implements ProcessInterface
{
    /**
     * The command and arguments, as given to the constructor (before command resolution).
     *
     * @var string[]
     */
    private array $command;

    /**
     * The proc_open resource.
     *
     * @var resource
     */
    private mixed $process = null;

    /**
     * The stream resources to communicate with the process.
     *
     * @var resource[]
     */
    private array $pipes;

    /**
     * The last retrieved status from proc_get_status().
     *
     * @var array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int}
     */
    private ?array $status = null;

    /**
     * Launch a new process.
     *
     * @param string[]              $command The command and arguments as array elements
     * @param string                $cwd     The working directory of the process
     * @param array<string, string> $env     The environment for the process (or null to inherit env from the PHP process)
     *
     * @return void
     */
    public function __construct(array $command, ?string $cwd = null, ?array $env = null)
    {
        if (\PHP_OS_FAMILY === 'Windows' && \PHP_VERSION_ID < 80300) {
            throw new \LogicException('phasync\Process needs PHP 8.3 or later on Windows');
        }
        $this->command = $command;

        if ([] === $command) {
            throw new \RuntimeException('Process could not be started: no command given');
        }

        $resolvedCommand    = $command;
        $resolvedCommand[0] = self::resolveCommand($command[0], $cwd, $env);

        \set_error_handler(static function (int $code, string $message): never {
            throw new \RuntimeException("Process could not be started: Errno: {$code}; {$message}");
        });

        // Every standard descriptor is a full-duplex socket, on every platform: see the class
        // docblock for why this replaced ['pipe', 'r'|'w']. On Windows, bypass_shell keeps
        // command resolution here the only place arguments are ever interpreted -- otherwise
        // proc_open() wraps everything in `cmd /c`, which is exactly the shell involvement
        // PRC-2 rules out on POSIX and must not silently reappear on Windows. A .bat/.cmd
        // command still runs through cmd.exe, which Windows starts for batch files itself.
        $descriptorSpec  = [['socket'], ['socket'], ['socket']];
        $otherOptions    = \PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : [];

        try {
            $process = [] === $otherOptions
                ? @\proc_open($resolvedCommand, $descriptorSpec, $pipes, $cwd, $env)
                : @\proc_open($resolvedCommand, $descriptorSpec, $pipes, $cwd, $env, $otherOptions);
        } finally {
            \restore_error_handler();
        }

        if (!\is_resource($process)) {
            throw new \RuntimeException("Failed to launch process '" . \implode(' ', $command) . "'");
        }

        $this->process = $process;
        $this->pipes   = $pipes;

        foreach ($this->pipes as $pipe) {
            \stream_set_blocking($pipe, false);
            \stream_set_read_buffer($pipe, 0);
            \stream_set_write_buffer($pipe, 0);
        }

        $this->poll();

        // A best effort, non-blocking check for the one class of failure that resolveCommand()
        // cannot see: the target resolved and was executable, proc_open() forked/spawned
        // successfully, but the child still failed to start -- for example a POSIX `#!`
        // interpreter line that points at an interpreter which does not exist, or the file
        // being removed between the check above and the fork. Whether the child has already
        // failed by this point is a race, so this only catches what already happened;
        // resolveCommand() is the reliable guard, this is a bonus.
        if (!$this->status['running'] && \in_array($this->status['exitcode'], [126, 127], true)) {
            throw new \RuntimeException("Process could not be started: '{$command[0]}' exited immediately with code {$this->status['exitcode']}");
        }
    }

    /**
     * Resolve $command the way the platform's own loader would, and throw if nothing
     * executable is found, before proc_open() is ever called. See resolveCommandPosix() and
     * resolveCommandWindows() for the platform-specific rules.
     *
     * @throws \RuntimeException
     */
    private static function resolveCommand(string $command, ?string $cwd, ?array $env): string
    {
        if ('' === $command) {
            throw new \RuntimeException('Process could not be started: no command given');
        }

        return \PHP_OS_FAMILY === 'Windows'
            ? self::resolveCommandWindows($command, $cwd, $env)
            : self::resolveCommandPosix($command, $cwd, $env);
    }

    /**
     * Resolve $command the way exec() would -- a literal path if it contains a slash, otherwise a
     * search through PATH -- and throw if nothing executable is found.
     *
     * proc_open() performs this same resolution itself, but whether it *reports* a missing
     * executable synchronously depends on the platform's glibc version and how PHP was built
     * against it (see the posix_spawn() pipe-based error reporting added in glibc 2.24): on some
     * platforms proc_open() fails outright, on others it silently returns a resource whose child
     * has already failed. This check does not depend on that and is reliable everywhere.
     *
     * @throws \RuntimeException
     */
    private static function resolveCommandPosix(string $command, ?string $cwd, ?array $env): string
    {
        if (\str_contains($command, '/')) {
            $path = (\str_starts_with($command, '/') || null === $cwd) ? $command : \rtrim($cwd, '/') . '/' . $command;
            if (!\is_file($path) || !\is_executable($path)) {
                throw new \RuntimeException("Process could not be started: '{$command}' is not an executable file");
            }

            return $command;
        }

        // proc_open() replaces the child's entire environment when $env is given, so PATH must
        // come from there, not from this (the parent) process. If $env was given without a PATH
        // entry, the platform's own fallback search path applies and is not something this
        // check can predict, so it is intentionally not enforced here; proc_open() and the
        // status check above remain the guard for that rare combination.
        $pathEnv = null !== $env ? ($env['PATH'] ?? null) : \getenv('PATH');
        if ('' === $pathEnv) {
            throw new \RuntimeException("Process could not be started: '{$command}' was not found (PATH is empty)");
        }
        if (null === $pathEnv) {
            return $command;
        }

        foreach (\explode(\PATH_SEPARATOR, $pathEnv) as $dir) {
            // An empty PATH entry traditionally means "the current directory" (a well known
            // POSIX footgun: PATH=:/usr/bin searches . before anything else). This check does
            // not follow that convention.
            if ('' === $dir) {
                continue;
            }
            $candidate = \rtrim($dir, '/') . '/' . $command;
            if (\is_file($candidate) && \is_executable($candidate)) {
                return $command;
            }
        }

        throw new \RuntimeException("Process could not be started: '{$command}' was not found in PATH");
    }

    /**
     * Resolve $command the way Windows' loader would -- a literal path (optionally missing its
     * extension) if it contains a `\`, `/` or drive letter, otherwise a search through PATH --
     * trying PATHEXT's extensions in order, and returns the fully resolved, extension-qualified
     * path. Unlike the POSIX path, this returns a *rewritten* command: proc_open()'s
     * `bypass_shell` option calls CreateProcess() directly, which -- unlike cmd.exe -- does not
     * itself search PATH or try PATHEXT's extensions for an extension-less name, so this must
     * hand it something already resolved.
     *
     * `is_executable()` is not used here: on Windows it reflects whether PHP's stdio layer
     * considers the extension directly launchable, not real permission bits (Windows files carry
     * no POSIX executable bit), so it is not a meaningful proxy for "CreateProcess can launch
     * this" for an arbitrary PATHEXT extension. Existence via is_file(), combined with a
     * recognised PATHEXT extension, is used instead.
     *
     * @throws \RuntimeException
     */
    private static function resolveCommandWindows(string $command, ?string $cwd, ?array $env): string
    {
        $pathExt = self::windowsPathExt();

        if (self::hasWindowsPathSeparator($command) || self::hasWindowsDrive($command)) {
            $path = (self::isWindowsAbsolute($command) || null === $cwd)
                ? $command
                : \rtrim($cwd, '\\/') . '\\' . $command;
            $resolved = self::findWindowsExecutable($path, $pathExt);
            if (null === $resolved) {
                throw new \RuntimeException("Process could not be started: '{$command}' is not an executable file");
            }

            return $resolved;
        }

        // Deliberately does not also search $cwd for a bare name, even though cmd.exe's own
        // default search order does: matching that would reintroduce, on Windows, the same
        // "current directory searched first" footgun the POSIX path above deliberately avoids
        // for an empty PATH entry.
        $pathEnv = null !== $env ? ($env['PATH'] ?? $env['Path'] ?? null) : \getenv('PATH');
        if ('' === $pathEnv) {
            throw new \RuntimeException("Process could not be started: '{$command}' was not found (PATH is empty)");
        }
        if (null === $pathEnv) {
            return $command;
        }

        foreach (\explode(\PATH_SEPARATOR, $pathEnv) as $dir) {
            if ('' === $dir) {
                continue;
            }
            $resolved = self::findWindowsExecutable(\rtrim($dir, '\\/') . '\\' . $command, $pathExt);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        throw new \RuntimeException("Process could not be started: '{$command}' was not found in PATH");
    }

    /**
     * @return string[] Each entry includes its leading dot, e.g. '.EXE'
     */
    private static function windowsPathExt(): array
    {
        $raw  = \getenv('PATHEXT');
        $exts = $raw ? \array_filter(\explode(';', $raw)) : [];

        return $exts ?: ['.COM', '.EXE', '.BAT', '.CMD'];
    }

    private static function hasWindowsPathSeparator(string $command): bool
    {
        return \str_contains($command, '/') || \str_contains($command, '\\');
    }

    private static function hasWindowsDrive(string $command): bool
    {
        return isset($command[1]) && ':' === $command[1];
    }

    private static function isWindowsAbsolute(string $command): bool
    {
        return self::hasWindowsDrive($command) || \str_starts_with($command, '\\') || \str_starts_with($command, '/');
    }

    /**
     * If $base's own extension is one of $pathExt's, it must exist as given. Otherwise, try each
     * of $pathExt's extensions appended, the way cmd.exe resolves an extension-less command name.
     * An existing file whose extension is *not* in $pathExt (a `.txt`, or none) is not considered
     * executable -- CreateProcess would not launch it either -- the Windows equivalent of the
     * POSIX path's executable-bit check.
     */
    private static function findWindowsExecutable(string $base, array $pathExt): ?string
    {
        $pathExtLower = \array_map(\strtolower(...), $pathExt);
        $ext          = '.' . \strtolower((string) \pathinfo($base, \PATHINFO_EXTENSION));
        if (\in_array($ext, $pathExtLower, true)) {
            return \is_file($base) ? $base : null;
        }

        foreach ($pathExt as $extension) {
            $candidate = $base . $extension;
            if (\is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function getStream(int $fd): mixed
    {
        return $this->pipes[$fd] ?? null;
    }

    public function stop(): bool
    {
        if ($this->isRunning()) {
            $this->sigterm();
            $t = \microtime(true);
            while ($this->isRunning() && \microtime(true) - $t < 1) {
                if (\phasync::isRunning()) {
                    \phasync::sleep(0.05);
                } else {
                    \usleep(50000);
                }
            }
            if ($this->isRunning()) {
                $this->sigkill();
            }
            while ($this->isRunning() && \microtime(true) - $t < 5) {
                \phasync::sleep(0.05);
            }
        }

        return !$this->isRunning();
    }

    /**
     * Returns true if the process is still running.
     */
    public function isRunning(): bool
    {
        $this->poll();

        return $this->status['running'];
    }

    /**
     * Returns true if the process is stopped (generally via
     * {@see Process::sigstop()}).
     *
     * Always false on Windows: there is no signal there that pauses a process the way SIGSTOP
     * does (see sigstop()).
     */
    public function isStopped(): bool
    {
        $this->poll();

        return $this->status['stopped'];
    }

    /**
     * Return the exitcode of the process, or false if the process
     * is still running.
     */
    public function getExitCode(): int|false
    {
        if (!$this->isRunning()) {
            return $this->status['exitcode'];
        }

        return false;
    }

    /**
     * Send a POSIX signal to the process.
     *
     * Sends a specified POSIX signal to the running process. If the process
     * is not running, the function will return false without sending a signal.
     *
     * On Windows, proc_terminate() (what this calls) cannot deliver a specific signal: $signal
     * is accepted for interface compatibility but ignored, and every call forcibly ends the
     * process outright, the same as sigkill() on POSIX. This means sigstop()/sigcont() cannot
     * pause or resume a process there, and sigterm()/sigint()/sighup() cannot ask a Windows
     * child to shut down gracefully -- they hard-kill it, same as sigkill().
     *
     * @param int $signal the signal number to send (default: SIGTERM); ignored on Windows
     *
     * @return bool true if the signal was successfully sent, false otherwise
     */
    public function sendSignal(int $signal = 15): bool
    {
        if (!$this->isRunning()) {
            return false;
        }

        return \proc_terminate($this->process, $signal);
    }

    /**
     * SIGTERM requests a process to terminate. It is a polite way to tell
     * a process to stop running. Unlike SIGKILL, this signal can be caught,
     * handled, and ignored, allowing a process to shut down gracefully.
     *
     * On Windows this hard-kills the process instead; see sendSignal().
     *
     * @throws \LogicException
     */
    public function sigterm(): bool
    {
        return $this->sendSignal(15);
    }

    /**
     * This signal forces a process to terminate immediately. Operating
     * systems typically use SIGKILL to deal with unresponsive processes.
     * It cannot be caught, handled, or ignored, making it a surefire
     * but potentially unsafe way to stop a process as it does not allow
     * for clean-up operations.
     *
     * @throws \LogicException
     */
    public function sigkill(): bool
    {
        return $this->sendSignal(9);
    }

    /**
     * This signal is typically sent when the user types the interrupt
     * character (usually Ctrl+C). It tells the process to interrupt
     * its current activity. SIGINT allows the process to clean up
     * nicely, releasing resources and saving state if necessary before
     * exiting.
     *
     * On Windows this hard-kills the process instead; see sendSignal().
     *
     * @throws \LogicException
     */
    public function sigint(): bool
    {
        return $this->sendSignal(2);
    }

    /**
     * This signal pauses a process's execution. It can be used to
     * temporarily stop a process for later resumption with SIGCONT. Like
     * SIGKILL, SIGSTOP cannot be caught, handled, or ignored.
     *
     * Not supported on Windows: proc_terminate() has no way to pause a process, so this
     * hard-kills it instead of pausing it; see sendSignal().
     *
     * @throws \LogicException
     */
    public function sigstop(): bool
    {
        return $this->sendSignal(19);
    }

    /**
     * SIGCONT is used to resume a process previously stopped by SIGSTOP
     * or another stop signal. This signal allows for job control as well
     * as pausing and resuming processes.
     *
     * Not supported on Windows; see sigstop() and sendSignal().
     *
     * @throws \LogicException
     */
    public function sigcont(): bool
    {
        return $this->sendSignal(18);
    }

    /**
     * Originally sent when a terminal was closed, today SIGHUP is often
     * used to instruct background processes to reload their configuration
     * files or to gracefully restart. It can be caught and handled, which
     * allows applications to perform specific actions, such as re-reading
     * a configuration file.
     *
     * On Windows this hard-kills the process instead; see sendSignal().
     */
    public function sighup(): bool
    {
        return $this->sendSignal(1);
    }

    /**
     * Update the process status, unless the process is no longer running.
     */
    private function poll(): void
    {
        if (null !== $this->status && !$this->status['running']) {
            // process has terminated
            return;
        }
        $this->status = \proc_get_status($this->process);

        // Free the resources
        if (!$this->status['running']) {
            $this->process = null;
            foreach ($this->pipes as $pipe) {
                if (\is_resource($pipe)) {
                    \fclose($pipe);
                }
            }
        }
    }

    /**
     * Perform a Fiber-blocking read from the given process pipe.
     *
     * @param int $fd The file descriptor number
     *
     * @return string|null
     */
    public function read(int $fd = ProcessInterface::STDOUT): string|false
    {
        \phasync::readable($this->pipes[$fd]);

        return \stream_get_contents($this->pipes[$fd]);
    }

    /**
     * Perform a Fiber-blocking write to the given process pipe.
     *
     * @throws \FiberError
     * @throws \Throwable
     */
    public function write(string $data, int $fd = ProcessInterface::STDIN): int|false
    {
        if (!$this->isRunning()) {
            return false;
        }
        \phasync::writable($this->pipes[$fd]);

        return \fwrite($this->pipes[$fd], $data);
    }
}
