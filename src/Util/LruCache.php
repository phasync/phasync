<?php

namespace phasync\Util;

/**
 * A bounded in-memory cache that forgets the least recently used entries first.
 *
 * ```php
 * $cache = new LruCache(maxEntries: 10_000, maxBytes: 64 << 20);
 * $cache->set('user:42', $json, ttl: 60);
 * $json = $cache->get('user:42');   // null once expired or evicted
 * ```
 *
 * - Reading an entry (get(), has()) makes it the most recently used; setting one does too.
 * - Past $maxEntries entries, or $maxBytes bytes, the least recently used go first. The bytes
 *   are those of each key and of string values; other values count their key only.
 * - An entry with a TTL is gone after that many seconds, by the monotonic clock. Expired
 *   entries are dropped when read, and otherwise evicted as the least recently used.
 * - Every operation is O(1): the entries are one PHP array in the order of their last use.
 *
 * @template T
 */
final class LruCache implements \Countable
{
    /** @var array<array-key, T> the entries, least recently used first */
    private array $values = [];

    /** @var array<array-key, int> when each entry that has a TTL expires, in hrtime nanoseconds */
    private array $expires = [];

    /** @var array<array-key, int> the bytes of each entry, when is set */
    private array $sizes = [];

    private int $bytes = 0;

    /**
     * Creates an empty cache.
     *
     * @param int $maxEntries the most entries held; the least recently used go first
     * @param int $maxBytes   the most bytes held, counting keys and string values
     *
     * @throws \InvalidArgumentException when either is below 1
     */
    public function __construct(
        private readonly int $maxEntries = \PHP_INT_MAX,
        private readonly int $maxBytes = \PHP_INT_MAX,
    ) {
        if ($maxEntries < 1 || $maxBytes < 1) {
            throw new \InvalidArgumentException('An LruCache holds at least one entry and one byte');
        }
    }

    /**
     * Returns the value stored under `$key`, and makes it the most recently used.
     *
     * @param string $key     the key
     * @param mixed  $default returned when there is no entry, or it expired
     *
     * @return T|mixed the value, or `$default`
     *
     * @see LruCache::has
     * @see LruCache::set
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!\array_key_exists($key, $this->values) || $this->expired($key)) {
            return $default;
        }
        $value = $this->values[$key];
        unset($this->values[$key]);
        $this->values[$key] = $value;

        return $value;
    }

    /**
     * Returns whether there is an entry for `$key`, and makes it the most recently used if there is.
     *
     * @see LruCache::get
     */
    public function has(string $key): bool
    {
        if (!\array_key_exists($key, $this->values) || $this->expired($key)) {
            return false;
        }
        $value = $this->values[$key];
        unset($this->values[$key]);
        $this->values[$key] = $value;

        return true;
    }

    /**
     * Stores `$value` under `$key`, making room as needed.
     *
     * Returns false when the value alone is larger than `$maxBytes`: it is not stored, and an
     * older value under `$key` is removed.
     *
     * @param T          $value
     * @param float|null $ttl   seconds until it expires; null for never
     */
    public function set(string $key, mixed $value, ?float $ttl = null): bool
    {
        $this->delete($key);
        if (\PHP_INT_MAX !== $this->maxBytes) {
            $size = \strlen($key) + (\is_string($value) ? \strlen($value) : 0);
            if ($size > $this->maxBytes) {
                return false;
            }
            $this->sizes[$key] = $size;
            $this->bytes += $size;
        }
        $this->values[$key] = $value;
        if (null !== $ttl) {
            $this->expires[$key] = \hrtime(true) + (int) ($ttl * 1e9);
        }
        while (\count($this->values) > $this->maxEntries || $this->bytes > $this->maxBytes) {
            $this->delete((string) \array_key_first($this->values));
        }

        return true;
    }

    /** Whether there was an entry for $key. */
    public function delete(string $key): bool
    {
        if (!\array_key_exists($key, $this->values)) {
            return false;
        }
        unset($this->values[$key], $this->expires[$key]);
        if (isset($this->sizes[$key])) {
            $this->bytes -= $this->sizes[$key];
            unset($this->sizes[$key]);
        }

        return true;
    }

    /**
     * Removes every entry.
     */
    public function clear(): void
    {
        $this->values  = [];
        $this->expires = [];
        $this->sizes   = [];
        $this->bytes   = 0;
    }

    /** The entries held, expired ones not yet dropped included. */
    public function count(): int
    {
        return \count($this->values);
    }

    /** The bytes held, when $maxBytes is set. */
    public function bytes(): int
    {
        return $this->bytes;
    }

    /** Drop the entry when it expired. */
    private function expired(string $key): bool
    {
        if (isset($this->expires[$key]) && \hrtime(true) >= $this->expires[$key]) {
            $this->delete($key);

            return true;
        }

        return false;
    }
}
