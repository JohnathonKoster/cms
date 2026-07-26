<?php

namespace Statamic\View\Instrumentation\Concerns;

/**
 * Instrumented-result memoization: per-instance by default, shared
 * process-wide for deterministic config-built instances.
 */
trait MemoizesResults
{
    /**
     * Clears memoized results; every configuration change invalidates them.
     * A shared bucket cannot describe a mutated configuration, so the
     * instance also leaves its bucket.
     *
     * @return void
     */
    protected function flushResultCache()
    {
        $this->resultCache = [];

        if ($this->cacheScope !== null) {
            unset(static::$sharedResultCache[$this->cacheScope]);
            $this->cacheScope = null;
        }
    }

    /**
     * Drops every process-wide memoized result (all configuration buckets).
     *
     * @return void
     */
    public static function flushSharedResultCache()
    {
        static::$sharedResultCache = [];
    }

    /**
     * @param  string  $key
     * @return string|null
     */
    protected function cachedResult($key)
    {
        if ($this->cacheScope !== null) {
            return static::$sharedResultCache[$this->cacheScope][$key] ?? null;
        }

        return $this->resultCache[$key] ?? null;
    }

    /**
     * @param  string  $key
     * @param  string  $result
     * @return string
     */
    protected function storeResult($key, $result)
    {
        if ($this->cacheScope !== null) {
            static::$sharedResultCache[$this->cacheScope] = $this->evict(
                static::$sharedResultCache[$this->cacheScope] ?? []
            );

            return static::$sharedResultCache[$this->cacheScope][$key] = $result;
        }

        $this->resultCache = $this->evict($this->resultCache);

        return $this->resultCache[$key] = $result;
    }

    /**
     * Discards the oldest half of a full cache instead of emptying it. PHP
     * arrays preserve insertion order, so the most recently instrumented
     * templates survive; clearing outright would drive the hit rate to zero
     * for any site whose working set exceeds the limit.
     *
     * @param  array<string, string>  $cache
     * @return array<string, string>
     */
    protected function evict(array $cache)
    {
        if (count($cache) < $this->resultCacheLimit) {
            return $cache;
        }

        return array_slice($cache, intdiv($this->resultCacheLimit, 2), null, true);
    }
}
