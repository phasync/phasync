<?php

namespace phasync\Psr;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

class UploadedFile implements UploadedFileInterface
{
    protected StreamInterface $stream;
    protected ?string $path      = null;
    protected ?string $filename  = null;
    protected ?string $mediaType = null;
    protected ?string $error     = null;
    protected ?int $size         = null;

    public function __construct(StreamInterface $stream, ?int $size=null, int $error = \UPLOAD_ERR_OK, ?string $clientFilename = null, ?string $clientMediaType = null)
    {
        $this->stream    = $stream;
        $this->size      = $size;
        $this->error     = $error;
        $this->filename  = $clientFilename;
        $this->mediaType = $clientMediaType;
    }

    /**
     * Retrieve a stream representing the uploaded file.
     *
     * This method MUST return a StreamInterface instance, representing the
     * uploaded file. The purpose of this method is to allow utilizing native PHP
     * stream functionality to manipulate the file upload, such as
     * stream_copy_to_stream() (though the result will need to be decorated in a
     * native PHP stream wrapper to work with such functions).
     *
     * If the moveTo() method has been called previously, this method MUST raise
     * an exception.
     *
     * @throws \RuntimeException in cases when no stream is available
     * @throws \RuntimeException in cases when no stream can be created
     *
     * @return StreamInterface stream representation of the uploaded file
     */
    public function getStream(): StreamInterface
    {
        $this->assertNoError();

        return $this->stream;
    }

    /**
     * Move the uploaded file to a new location.
     *
     * Use this method as an alternative to move_uploaded_file(). This method is
     * guaranteed to work in both SAPI and non-SAPI environments.
     * Implementations must determine which environment they are in, and use the
     * appropriate method (move_uploaded_file(), rename(), or a stream
     * operation) to perform the operation.
     *
     * $targetPath may be an absolute path, or a relative path. If it is a
     * relative path, resolution should be the same as used by PHP's rename()
     * function.
     *
     * The original file or stream MUST be removed on completion.
     *
     * If this method is called more than once, any subsequent calls MUST raise
     * an exception.
     *
     * When used in an SAPI environment where $_FILES is populated, when writing
     * files via moveTo(), is_uploaded_file() and move_uploaded_file() SHOULD be
     * used to ensure permissions and upload status are verified correctly.
     *
     * If you wish to move to a stream, use getStream(), as SAPI operations
     * cannot guarantee writing to stream destinations.
     *
     * @see http://php.net/is_uploaded_file
     * @see http://php.net/move_uploaded_file
     *
     * @param string $targetPath path to which to move the uploaded file
     *
     * @throws \InvalidArgumentException if the $targetPath specified is invalid
     * @throws \RuntimeException         on any error during the move operation
     * @throws \RuntimeException         on the second or subsequent call to the method
     */
    public function moveTo($targetPath): void
    {
        $this->assertNoError();
        if (null !== $this->stream) {
            // we have a stream reference to the upload, so we'll read from that
            if (!\rewind($this->stream)) {
                throw new \RuntimeException('Unable to rewind the incoming upload stream');
            }
            $fp = \fopen($targetPath, 'xn');
            if (!$fp) {
                throw new \InvalidArgumentException("Unable to create file '$targetPath'");
            }
            while (!\feof($this->stream)) {
                $chunk = \fread($this->stream, 8192);
                if (false === $chunk) {
                    \unlink($targetPath);
                    throw new \RuntimeException('fread() failed on incoming upload stream');
                }
                $written = \fwrite($fp, $chunk);
                if (!\is_int($written)) {
                    \unlink($targetPath);
                    throw new \RuntimeException('fwrite() failed on the destination file');
                }
            }
            \fclose($fp);
            \fclose($this->stream);

            return;
        }
        if ($this->path) {
            if (!(isset($_FILES) && \is_uploaded_file($this->path))) {
                throw new \RuntimeException('The file is not an uploaded file');
            }
            \rename($this->path, $targetPath);

            return;
        }
        throw new \RuntimeException('Unable to move uploaded file');
    }

    /**
     * Retrieve the file size.
     *
     * Implementations SHOULD return the value stored in the "size" key of
     * the file in the $_FILES array if available, as PHP calculates this based
     * on the actual size transmitted.
     *
     * @return int|null the file size in bytes or null if unknown
     */
    public function getSize(): ?int
    {
        if (\is_int($this->size)) {
            return $this->size;
        }
        if ($this->stream && \is_resource($this->stream)) {
            $stat = \fstat($this->stream);
            if ($stat && \key_exists('size', $stat)) {
                return $this->size = $stat['size'];
            }
        }
        if ($this->path && \file_exists($this->path)) {
            return $this->size = \filesize($this->path);
        }

        return null;
    }

    /**
     * Retrieve the error associated with the uploaded file.
     *
     * The return value MUST be one of PHP's UPLOAD_ERR_XXX constants.
     *
     * If the file was uploaded successfully, this method MUST return
     * UPLOAD_ERR_OK.
     *
     * Implementations SHOULD return the value stored in the "error" key of
     * the file in the $_FILES array.
     *
     * @see http://php.net/manual/en/features.file-upload.errors.php
     *
     * @return int one of PHP's UPLOAD_ERR_XXX constants
     */
    public function getError(): int
    {
        return $this->error;
    }

    /**
     * Retrieve the filename sent by the client.
     *
     * Do not trust the value returned by this method. A client could send
     * a malicious filename with the intention to corrupt or hack your
     * application.
     *
     * Implementations SHOULD return the value stored in the "name" key of
     * the file in the $_FILES array.
     *
     * @return string|null the filename sent by the client or null if none
     *                     was provided
     */
    public function getClientFilename(): ?string
    {
        return $this->filename;
    }

    /**
     * Retrieve the media type sent by the client.
     *
     * Do not trust the value returned by this method. A client could send
     * a malicious media type with the intention to corrupt or hack your
     * application.
     *
     * Implementations SHOULD return the value stored in the "type" key of
     * the file in the $_FILES array.
     *
     * @return string|null the media type sent by the client or null if none
     *                     was provided
     */
    public function getClientMediaType(): ?string
    {
        return $this->mediaType;
    }

    /**
     * Check that no error condition exists which should prevent this operation
     */
    protected function assertNoError(): void
    {
        if (\UPLOAD_ERR_OK !== $this->error) {
            throw new \RuntimeException('Upload error code ' . $this->error . ' prevented this operation');
        }
    }
}
