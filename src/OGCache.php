<?php

namespace ottimis\phplibs;

use RuntimeException;

class OGCache
{
    private static array $instances = [];
    private \Redis $redis;
    private string $prefix;

    public function __construct(string $prefix = '', string $connectionName = 'default')
    {
        $host = getenv('REDIS_HOST') ?: getenv('REDIS_SERVICE_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDIS_PORT') ?: getenv('REDIS_SERVICE_PORT') ?: 6379);
        $password = getenv('REDIS_PASSWORD') ?: null;
        $database = (int)(getenv('REDIS_DATABASE') ?: 0);
        $scheme = getenv('REDIS_SCHEME') ?: getenv('REDIS_SERVICE_SCHEME') ?: 'tcp';

        $this->prefix = $prefix !== '' ? rtrim($prefix, ':') . ':' : '';

        $this->redis = new \Redis();

        if ($scheme === 'tls') {
            $connected = $this->redis->connect('tls://' . $host, $port, 5);
        } else {
            $connected = $this->redis->connect($host, $port, 5);
        }

        if (!$connected) {
            throw new RuntimeException("Failed to connect to Redis at $host:$port");
        }

        if ($password) {
            $this->redis->auth($password);
        }

        if ($database > 0) {
            $this->redis->select($database);
        }
    }

    /**
     * Get a singleton instance by connection name.
     */
    public static function getInstance(string $prefix = '', string $connectionName = 'default'): self
    {
        $key = $connectionName . ':' . $prefix;
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($prefix, $connectionName);
        }

        return self::$instances[$key];
    }

    /**
     * Create a new (non-singleton) instance.
     */
    public static function createNew(string $prefix = '', string $connectionName = 'default'): self
    {
        return new self($prefix, $connectionName);
    }

    /**
     * Get a cached value.
     * Returns null if the key does not exist.
     */
    public function get(string $key): mixed
    {
        $value = $this->redis->get($this->prefix . $key);
        if ($value === false) {
            return null;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * Store a value in cache.
     *
     * @param string $key
     * @param mixed $value Any serializable value (arrays, objects, scalars)
     * @param int $ttl Time to live in seconds. 0 = no expiration.
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $serialized = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);

        if ($ttl > 0) {
            return $this->redis->setex($this->prefix . $key, $ttl, $serialized);
        }

        return $this->redis->set($this->prefix . $key, $serialized);
    }

    /**
     * Check if a key exists.
     */
    public function has(string $key): bool
    {
        return (bool)$this->redis->exists($this->prefix . $key);
    }

    /**
     * Delete one or more keys.
     */
    public function delete(string ...$keys): int
    {
        $prefixedKeys = array_map(fn(string $k) => $this->prefix . $k, $keys);
        return $this->redis->del($prefixedKeys);
    }

    /**
     * Delete all keys matching a glob-style pattern.
     * Example: clear('product_*') deletes product_list, product_42, etc.
     *
     * Uses SCAN internally to avoid blocking the server.
     */
    public function clear(string $pattern = '*'): int
    {
        $fullPattern = $this->prefix . $pattern;
        $deleted = 0;
        $iterator = null;

        while ($keys = $this->redis->scan($iterator, $fullPattern, 100)) {
            $deleted += $this->redis->del($keys);
        }

        return $deleted;
    }

    /**
     * Get a value or compute it if not cached.
     *
     * @param string $key
     * @param callable $callback Must return the value to cache
     * @param int $ttl Time to live in seconds. 0 = no expiration.
     */
    public function remember(string $key, callable $callback, int $ttl = 0): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /**
     * Increment an integer value.
     */
    public function increment(string $key, int $by = 1): int
    {
        return $this->redis->incrBy($this->prefix . $key, $by);
    }

    /**
     * Decrement an integer value.
     */
    public function decrement(string $key, int $by = 1): int
    {
        return $this->redis->decrBy($this->prefix . $key, $by);
    }

    /**
     * Set a key's time to live in seconds.
     */
    public function expire(string $key, int $ttl): bool
    {
        return $this->redis->expire($this->prefix . $key, $ttl);
    }

    /**
     * Get the remaining TTL of a key in seconds.
     * Returns -1 if the key has no expiration, -2 if it does not exist.
     */
    public function ttl(string $key): int
    {
        return $this->redis->ttl($this->prefix . $key);
    }

    /**
     * Access the underlying Redis instance for advanced operations.
     */
    public function getRedis(): \Redis
    {
        return $this->redis;
    }
}