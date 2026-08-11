<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Shared stale-while-revalidate cache helper.
 *
 * Values are always served immediately from cache when available; refreshes are
 * deduplicated through a short lock and executed after the response has already
 * been sent.
 */
final class DayZStaleCacheService
{
    /** @var array<string, bool> */
    private static array $deferred = [];

    /**
     * @param callable(): mixed $resolver
     */
    public function remember(
        string $key,
        int $freshSeconds,
        int $retentionSeconds,
        callable $resolver,
        mixed $fallbackWhenEmpty = null,
    ): mixed {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return $resolver();
        }

        try {
            $cached = \Illuminate\Support\Facades\Cache::get($key);
        } catch (Throwable) {
            return $resolver();
        }

        $hasValue = is_array($cached) && (
            (bool) ($cached['has_value'] ?? false) || array_key_exists('value', $cached)
        );
        $value = $hasValue ? ($cached['value'] ?? null) : null;
        $generatedAt = is_array($cached) ? (int) ($cached['generated_at'] ?? 0) : 0;
        $isFresh = $hasValue && (time() - $generatedAt) < $freshSeconds;

        if ($isFresh) {
            return $value;
        }

        if ($hasValue) {
            // Stale data exists — serve it immediately and refresh in the background
            // after the response has been sent so the caller is not blocked.
            $this->deferRefresh($key, $freshSeconds, $retentionSeconds, $resolver);

            return $value;
        }

        // No cached data at all.  Populate synchronously so the very first
        // request always returns real data rather than the empty fallback.
        // This is the correct stale-while-revalidate behaviour: serve stale
        // when stale data exists, but block on the initial cold-cache load so
        // nested resolvers (e.g. gatherStats → installedMods → scanInstalledMods)
        // are each populated before their parent caches are written, avoiding
        // the race where an outer cache is persisted with the inner cache's
        // empty fallback.
        $this->refreshNow($key, $freshSeconds, $retentionSeconds, $resolver);

        try {
            $refreshed = \Illuminate\Support\Facades\Cache::get($key);
            $refreshedHasValue = is_array($refreshed) && (
                (bool) ($refreshed['has_value'] ?? false) || array_key_exists('value', $refreshed)
            );

            if ($refreshedHasValue) {
                return $refreshed['value'] ?? null;
            }
        } catch (Throwable) {
            // Fall through to the fallback.
        }

        return $fallbackWhenEmpty;
    }

    /**
     * @param callable(): mixed $resolver
     */
    public function refreshNow(string $key, int $freshSeconds, int $retentionSeconds, callable $resolver): void
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            $resolver();
            return;
        }

        $lockKey = $key . '.lock';

        try {
            if (!\Illuminate\Support\Facades\Cache::add($lockKey, 1, max(5, $freshSeconds))) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        try {
            $value = $resolver();
            \Illuminate\Support\Facades\Cache::put(
                $key,
                ['has_value' => true, 'value' => $value, 'generated_at' => time()],
                max($retentionSeconds, $freshSeconds),
            );
        } catch (Throwable) {
            // Best-effort cache refresh only.
        } finally {
            try {
                \Illuminate\Support\Facades\Cache::forget($lockKey);
            } catch (Throwable) {
                // Best-effort lock cleanup.
            }
        }
    }

    /**
     * @param callable(): mixed $resolver
     */
    private function deferRefresh(string $key, int $freshSeconds, int $retentionSeconds, callable $resolver): void
    {
        $lockKey = $key . '.lock';

        try {
            if (!\Illuminate\Support\Facades\Cache::add($lockKey, 1, max(5, $freshSeconds))) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        if (array_key_exists($key, self::$deferred)) {
            // Lock was just acquired above but no shutdown function will be
            // registered (another one is still pending for this key).  Release
            // the lock so it is not held until its TTL expires.
            try {
                \Illuminate\Support\Facades\Cache::forget($lockKey);
            } catch (Throwable) {
                // Best-effort.
            }

            return;
        }

        self::$deferred[$key] = true;

        register_shutdown_function(function () use ($key, $lockKey, $freshSeconds, $retentionSeconds, $resolver): void {
            try {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }

                $value = $resolver();
                \Illuminate\Support\Facades\Cache::put(
                    $key,
                    ['has_value' => true, 'value' => $value, 'generated_at' => time()],
                    max($retentionSeconds, $freshSeconds),
                );
            } catch (Throwable) {
                // Best-effort cache refresh only.
            } finally {
                unset(self::$deferred[$key]);

                try {
                    \Illuminate\Support\Facades\Cache::forget($lockKey);
                } catch (Throwable) {
                    // Best-effort lock cleanup.
                }
            }
        });
    }
}
