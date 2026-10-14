<?php

namespace phasync\Psr;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A PSR-7 file uploaded with a request.
 *
 * Made from a stream resource, a file path (as `$_FILES['tmp_name']` gives it) or a `StreamInterface`.
 * The client's file name and media type are what the client sent: do not trust them.
 *
 * ```php
 * $file = new UploadedFile($_FILES['avatar']['tmp_name'], $_FILES['avatar']['name'], $_FILES['avatar']['type'], $_FILES['avatar']['size'], $_FILES['avatar']['error']);
 * if (UPLOAD_ERR_OK === $file->getError()) {
 *     $file->moveTo('/var/uploads/avatar.png');
 * }
 * ```
 *
 * @see ServerRequest::getUploadedFiles
 *
 * @topic streams-and-files
 *
 * @example examples/UploadedFile/1-store-an-upload.php Store an upload
 */
class UploadedFile implements UploadedFileInterface
{
    /** Stream resource or StreamInterface when constructed from one; null for file-path sources */
    protected mixed $stream = null;

    /** Resolved file path when constructed from a path; null for stream sources */
    protected ?string $path = null;

    /** Client-reported filename -- $_FILES['name']. Do not trust. */
    protected ?string $clientFilename;

    /** Client-reported MIME type -- $_FILES['type']. Do not trust. */
    protected ?string $clientMediaType;

    /** File size in bytes -- $_FILES['size'] */
    protected ?int $size;

    /** Upload error code -- one of PHP's UPLOAD_ERR_* constants */
    protected int $error;

    /** Override for is_uploaded_file() -- set true in tests */
    protected bool $isUploadedFile;

    /** Whether moveTo() has been called; PSR-7 requires a second call to throw */
    protected bool $moved = false;

    /**
     * Creates an uploaded file.
     *
     * @param resource|string|StreamInterface $source          stream resource, file path (tmp_name), or stream
     * @param ?string                         $clientFilename  client-reported filename
     * @param ?string                         $clientMediaType client-reported MIME type
     * @param ?int                            $size            file size in bytes
     * @param ?int                            $error           upload error code (an UPLOAD_ERR_* constant); null is UPLOAD_ERR_OK
     * @param bool                            $isUploadedFile  true to let `moveTo()` move a path that PHP did not receive as an upload (`rename()`); a path is otherwise moved only with `move_uploaded_file()`, or in the CLI with `rename()`
     *
     * @throws \InvalidArgumentException when `$source` is a path that does not exist (not for an upload that failed)
     */
    public function __construct(
        mixed $source,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
        ?int $size = null,
        ?int $error = null,
        bool $isUploadedFile = false,
    ) {
        $this->error           = $error ?? \UPLOAD_ERR_OK;
        $this->clientFilename  = $clientFilename;
        $this->clientMediaType = $clientMediaType;
        $this->size            = $size;
        $this->isUploadedFile  = $isUploadedFile;

        if (\is_resource($source) || $source instanceof StreamInterface) {
            $this->stream = $source;
        } elseif (\UPLOAD_ERR_OK === $this->error && '' !== $source) {
            // Only resolve a path for successful uploads; errored uploads have no temp file
            $resolved = \realpath($source);
            if (false === $resolved) {
                throw new \InvalidArgumentException("File path does not exist: '$source'");
            }
            $this->path = $resolved;
        }
    }

    public function getStream(): StreamInterface
    {
        if ($this->moved) {
            throw new \RuntimeException('Cannot retrieve stream after moveTo() has been called');
        }
        $this->assertNoError();

        if ($this->stream instanceof StreamInterface) {
            return $this->stream;
        }
        if (\is_resource($this->stream)) {
            return new ResourceStream($this->stream);
        }
        if (null !== $this->path && \file_exists($this->path)) {
            return new ResourceStream(\fopen($this->path, 'rb'));
        }
        throw new \RuntimeException('Uploaded file stream is unavailable');
    }

    /**
     * Moves the file to `$targetPath`, once.
     *
     * A file path is moved with `move_uploaded_file()`, or with `rename()` in the CLI or with `$isUploadedFile`. A stream is
     * copied to `$targetPath`. Either way an existing file at `$targetPath` is replaced.
     *
     * @throws \RuntimeException for a second call, an upload that failed (`getError()`), or a target that cannot be written
     *
     * @example examples/UploadedFile.moveTo/1-once-only.php A file moves once; a failed upload not at all
     */
    public function moveTo($targetPath): void
    {
        if ($this->moved) {
            throw new \RuntimeException('Uploaded file has already been moved');
        }
        $this->assertNoError();

        if (null !== $this->stream) {
            $this->moveStreamTo($targetPath);
        } elseif (null !== $this->path) {
            $this->moveFileTo($targetPath);
        } else {
            throw new \RuntimeException('No upload source available');
        }

        $this->moved = true;
    }

    /**
     * Copies a stream source to the target path, using the StreamInterface
     * contract when the source is a StreamInterface, or native functions
     * when it is a raw resource.
     */
    private function moveStreamTo(string $targetPath): void
    {
        $warning = null;
        \set_error_handler(static function (int $code, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $fp = \fopen($targetPath, 'wb'); // an existing target is replaced, as rename() does for a path
        } finally {
            \restore_error_handler();
        }
        if (!$fp) {
            throw new \RuntimeException("Unable to create file '$targetPath': " . ($warning ?? 'no error was reported'));
        }

        try {
            if ($this->stream instanceof StreamInterface) {
                if ($this->stream->isSeekable()) {
                    $this->stream->rewind();
                }
                while (!$this->stream->eof()) {
                    if (false === \fwrite($fp, $this->stream->read(8192))) {
                        throw new \RuntimeException('Failed writing to target file');
                    }
                }
                $this->stream->close();

                return;
            }

            if (!\rewind($this->stream)) {
                throw new \RuntimeException('Unable to rewind upload stream');
            }
            while (!\feof($this->stream)) {
                $chunk = \fread($this->stream, 8192);
                if (false === $chunk) {
                    throw new \RuntimeException('Failed reading from upload stream');
                }
                if (false === \fwrite($fp, $chunk)) {
                    throw new \RuntimeException('Failed writing to target file');
                }
            }
            \fclose($this->stream);
        } catch (\Throwable $e) {
            \fclose($fp);
            \unlink($targetPath);

            throw $e;
        }
        \fclose($fp);
    }

    /**
     * Moves a file-path source to the target path.
     *
     * In SAPI: uses move_uploaded_file() for security (PSR-7 SHOULD).
     * In CLI, or with the isUploadedFile override: uses rename().
     */
    private function moveFileTo(string $targetPath): void
    {
        if (\is_uploaded_file($this->path)) {
            if (!\move_uploaded_file($this->path, $targetPath)) {
                throw new \RuntimeException("move_uploaded_file() failed for: '$targetPath'");
            }
        } elseif ($this->isUploadedFile || \PHP_SAPI === 'cli') {
            if (!\rename($this->path, $targetPath)) {
                throw new \RuntimeException("Failed to move file to: '$targetPath'");
            }
        } else {
            throw new \RuntimeException('Source is not a valid uploaded file');
        }
    }

    public function getSize(): ?int
    {
        if (null !== $this->size) {
            return $this->size;
        }
        if ($this->stream instanceof StreamInterface) {
            return $this->size = $this->stream->getSize();
        }
        if (\is_resource($this->stream)) {
            $stat = \fstat($this->stream);
            if ($stat && isset($stat['size'])) {
                return $this->size = $stat['size'];
            }
        }
        if (null !== $this->path && \file_exists($this->path)) {
            return $this->size = \filesize($this->path);
        }

        return null;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    /**
     * @throws \RuntimeException if an upload error prevents the operation
     */
    protected function assertNoError(): void
    {
        if (\UPLOAD_ERR_OK !== $this->error) {
            throw new \RuntimeException('Upload error code ' . $this->error . ' prevented this operation');
        }
    }
}
