<?php

namespace phasync\Internal;

use Opis\Closure\SerializableClosure;
use phasync\Process\Process;
use phasync\Process\ProcessInterface;

final class WorkerPool
{
    private int $maxWorkers;
    private string $tempDir;
    private string $poolConfigPath;
    private string $poolSocketPath;
    private ProcessInterface $process;

    public function __construct(int $maxWorkers)
    {
        $this->maxWorkers     = \max(1, $maxWorkers);
        $this->tempDir        = $this->createUniqueTempDir();
        $this->poolConfigPath = $this->tempDir . '/pool.ini';
        $this->poolSocketPath = $this->tempDir . '/pool.sock';
        $this->writePoolConfig();

        echo 'COMMAND: ' . $this->getBinaryName() . ' ' . \implode(' ', $this->getArguments()) . "\n";
        $this->startProcess();
    }

    public function __destruct()
    {
        $this->stop();
        $this->cleanup();
    }

    private function createUniqueTempDir(): string
    {
        $tempDir = \sys_get_temp_dir() . '/workerpool_' . \uniqid();
        if (!\mkdir($tempDir) && !\is_dir($tempDir)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $tempDir));
        }

        return $tempDir;
    }

    private function writePoolConfig(): void
    {
        $vendorAutoload = $this->findComposerAutoload();
        $config         = "
[global]
error_log = {$this->tempDir}/task_worker_pool_error.log

[pool]
listen = {$this->poolSocketPath}
pm = ondemand
pm.max_children = {$this->maxWorkers}
pm.process_idle_timeout = 5s
access.log = {$this->tempDir}/task_worker_pool_access.log
php_admin_flag[log_errors] = on
php_admin_value[error_reporting] = E_ALL
php_admin_value[display_errors] = 'stderr'
php_admin_value[display_startup_errors] = 'On'
php_admin_value[auto_prepend_file] = {$vendorAutoload}
";
        \file_put_contents($this->poolConfigPath, $config);
    }

    private function findComposerAutoload(): string
    {
        $reflector    = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
        $vendorDir    = \dirname($reflector->getFileName(), 2);
        $autoloadPath = $vendorDir . '/autoload.php';

        if (\file_exists($autoloadPath)) {
            return $autoloadPath;
        }

        throw new \RuntimeException('Composer autoload.php not found');
    }

    private function startProcess(): void
    {
        $this->process = Process::run($this->getBinaryName(), $this->getArguments());
    }

    private function stop(): void
    {
        if (isset($this->process) && $this->process->isRunning()) {
            $this->process->stop();
        }
    }

    private function getArguments(): array
    {
        return ['-y', $this->poolConfigPath, '-F'];
    }

    private function getBinaryName(): string
    {
        return 'php-fpm' . \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION;
    }

    private function cleanup(): void
    {
        $files = \glob($this->tempDir . '/*');
        foreach ($files as $file) {
            if (\is_file($file)) {
                \unlink($file);
            }
        }
        \rmdir($this->tempDir);
    }

    public function invoke(callable $callable): mixed
    {
        $serializedCallable = \serialize(new SerializableClosure($callable));
        $response           = FastCGIHelper::sendFastCGIRequest(
            $this->poolSocketPath,
            __DIR__ . '/../workerpool.php',
            $serializedCallable
        );

        $responseData = $this->parseFastCGIResponse($response);

        if (isset($responseData['error']) && $responseData['error'] instanceof \Throwable) {
            throw $responseData['error'];
        }

        return $responseData['result'];
    }

    private function parseFastCGIResponse(string $response): array
    {
        // FastCGI responses have a header we need to skip
        $pos = \strpos($response, "\r\n\r\n");
        if (false === $pos) {
            throw new \RuntimeException('Invalid FastCGI response');
        }

        $body = \substr($response, $pos + 4);

        return \unserialize($body);
    }
}
