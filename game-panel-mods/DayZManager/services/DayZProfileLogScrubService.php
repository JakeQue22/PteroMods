<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Deletes old DayZ profile logs from /profiles using retention-based settings.
 */
final class DayZProfileLogScrubService
{
    private const RUN_INTERVAL_SECONDS = 900;

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZManagerSettingsService $settings = new DayZManagerSettingsService(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function tick(mixed $server): array
    {
        if (!(bool) $this->settings->get('auto_scrub_profile_logs', false)) {
            return ['status' => 'disabled'];
        }

        $serverKey = $this->context->attribute($server, ['uuid', 'uuidShort', 'id']);

        if ($serverKey === '') {
            return ['status' => 'skipped'];
        }

        $retentionDays = max(1, (int) $this->settings->get('profile_log_retention_days', 14));
        $cacheKey = 'pteromods.dayz.profile_log_scrub.last_run.' . md5($serverKey);

        if ($this->recentlyRan($cacheKey)) {
            return ['status' => 'throttled', 'retention_days' => $retentionDays];
        }

        $entries = $this->gateway->listDirectory($server, '/profiles');
        $cutoff = time() - ($retentionDays * 86400);
        $deleted = 0;
        $scanned = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry) || !($entry['file'] ?? false)) {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));
            $timestamp = $this->timestampFromName($name);

            if ($timestamp === null) {
                continue;
            }

            $scanned++;

            if ($timestamp >= $cutoff) {
                continue;
            }

            if ($this->gateway->deletePath($server, '/profiles/' . $name)) {
                $deleted++;
            }
        }

        $this->markRan($cacheKey);
        $this->gateway->clearFileListingCache($server, '/profiles');

        return [
            'status' => 'scrubbed',
            'retention_days' => $retentionDays,
            'scanned' => $scanned,
            'deleted' => $deleted,
        ];
    }

    private function timestampFromName(string $name): ?int
    {
        $patterns = [
            '/^script_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.log$/i',
            '/^crash_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.log$/i',
            '/^TM_GeneralLogs_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.log$/i',
        ];

        $stamp = '';

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $name, $matches) === 1) {
                $stamp = $matches[1];
                break;
            }
        }

        if ($stamp === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d_H-i-s', $stamp, new DateTimeZone('UTC'));

        return $date === false ? null : $date->getTimestamp();
    }

    private function recentlyRan(string $cacheKey): bool
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return false;
        }

        try {
            $last = \Illuminate\Support\Facades\Cache::get($cacheKey);
            return is_numeric($last) && ((int) $last) > (time() - self::RUN_INTERVAL_SECONDS);
        } catch (Throwable) {
            return false;
        }
    }

    private function markRan(string $cacheKey): void
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Cache::put($cacheKey, time(), self::RUN_INTERVAL_SECONDS * 2);
        } catch (Throwable) {
            // Best-effort cache write only.
        }
    }
}

