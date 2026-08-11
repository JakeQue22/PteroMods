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

        $retentionByType = [
            'script'         => max(1, (int) $this->settings->get('script_log_retention_days', 14)),
            'crash'          => max(1, (int) $this->settings->get('crash_log_retention_days', 14)),
            'tm_general_log' => max(1, (int) $this->settings->get('tm_general_log_retention_days', 14)),
        ];
        $cacheKey = 'pteromods.dayz.profile_log_scrub.last_run.' . md5($serverKey);

        if ($this->recentlyRan($cacheKey)) {
            return ['status' => 'throttled', 'retention_by_type' => $retentionByType];
        }

        $entries = $this->gateway->listDirectory($server, '/profiles');
        $now = time();
        $deleted = 0;
        $scanned = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry) || !($entry['file'] ?? false)) {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));
            [$timestamp, $logType] = $this->timestampAndTypeFromName($name);

            if ($timestamp === null || $logType === null) {
                continue;
            }

            $scanned++;
            $cutoff = $now - ($retentionByType[$logType] * 86400);

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
            'retention_by_type' => $retentionByType,
            'scanned' => $scanned,
            'deleted' => $deleted,
        ];
    }

    /**
     * Returns [timestamp, logType] for a recognised log file name, or [null, null].
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function timestampAndTypeFromName(string $name): array
    {
        $patterns = [
            'script'         => '/^script_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.log$/i',
            'crash'          => '/^crash_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.log$/i',
            'tm_general_log' => '/^TM_GeneralLogs_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.log$/i',
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $name, $matches) === 1) {
                $date = DateTimeImmutable::createFromFormat('Y-m-d_H-i-s', $matches[1], new DateTimeZone('UTC'));
                $timestamp = $date === false ? null : $date->getTimestamp();

                return [$timestamp, $type];
            }
        }

        return [null, null];
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

