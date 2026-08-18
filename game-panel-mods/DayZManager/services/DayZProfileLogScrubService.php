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
    private const RUN_INTERVAL_SECONDS = 86400;
    private const LOG_EXTENSIONS = ['log', 'rpt', 'txt', 'adm'];

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZManagerSettingsService $settings = new DayZManagerSettingsService(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function tick(mixed $server, bool $force = false): array
    {
        if (!$force && !(bool) $this->settings->get('auto_scrub_profile_logs', false)) {
            return ['status' => 'disabled'];
        }

        $serverKey = $this->context->attribute($server, ['uuid', 'uuidShort', 'id']);

        if ($serverKey === '') {
            return ['status' => 'skipped'];
        }

        $retentionByType = [
            'script'         => max(1, (int) $this->settings->get('script_log_retention_days', 14)),
            'crash'          => max(1, (int) $this->settings->get('crash_log_retention_days', 14)),
            'error'          => max(1, (int) $this->settings->get('error_log_retention_days', 14)),
            'tm_general_log' => max(1, (int) $this->settings->get('tm_general_log_retention_days', 14)),
            'trader_log'     => max(1, (int) $this->settings->get('trader_log_retention_days', 14)),
            'dzserver_adm'   => max(1, (int) $this->settings->get('dzserver_adm_log_retention_days', 14)),
            'dzserver_rpt'   => max(1, (int) $this->settings->get('dzserver_rpt_log_retention_days', 14)),
            'admin_log'      => max(1, (int) $this->settings->get('admin_log_retention_days', 14)),
            'airdrop_log'    => max(1, (int) $this->settings->get('airdrop_log_retention_days', 14)),
            'codelock_log'   => max(1, (int) $this->settings->get('codelock_log_retention_days', 14)),
        ];
        $cacheKey = 'pteromods.dayz.profile_log_scrub.last_run.' . md5($serverKey);

        if (!$force && $this->recentlyRan($cacheKey)) {
            return ['status' => 'throttled', 'retention_by_type' => $retentionByType];
        }

        $rules = [
            [
                'type' => 'script',
                'path' => '/profiles',
                'recursive' => false,
                'matcher' => static fn (string $name): bool => preg_match('/^script_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.log$/i', $name) === 1,
            ],
            [
                'type' => 'crash',
                'path' => '/profiles',
                'recursive' => false,
                'matcher' => static fn (string $name): bool => preg_match('/^crash_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.log$/i', $name) === 1,
            ],
            [
                'type' => 'error',
                'path' => '/profiles',
                'recursive' => false,
                'matcher' => static fn (string $name): bool => stripos($name, 'error') !== false,
            ],
            [
                'type' => 'tm_general_log',
                'path' => '/profiles',
                'recursive' => false,
                'matcher' => static fn (string $name): bool => preg_match('/^TM_GeneralLogs_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.log$/i', $name) === 1,
            ],
            [
                'type' => 'dzserver_adm',
                'path' => '/profiles',
                'recursive' => false,
                'matcher' => static fn (string $name): bool => preg_match('/^DayZServer_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.ADM$/i', $name) === 1,
            ],
            [
                'type' => 'dzserver_rpt',
                'path' => '/profiles',
                'recursive' => false,
                'matcher' => static fn (string $name): bool => preg_match('/^DayZServer_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.RPT$/i', $name) === 1,
            ],
            [
                'type' => 'trader_log',
                'path' => '/profiles',
                'recursive' => true,
                'matcher' => static fn (string $name): bool => str_starts_with(strtoupper($name), 'TM'),
            ],
            [
                'type' => 'admin_log',
                'path' => '/profiles/VPPAdminTools/Logging',
                'recursive' => true,
                'matcher' => static fn (string $name): bool => $name !== '',
            ],
            [
                'type' => 'airdrop_log',
                'path' => '/profiles/Airdrop/Logs',
                'recursive' => true,
                'matcher' => static fn (string $name): bool => $name !== '',
            ],
            [
                'type' => 'codelock_log',
                'path' => '/profiles/CodeLock/Logs',
                'recursive' => true,
                'matcher' => static fn (string $name): bool => $name !== '',
            ],
        ];

        $now = time();
        $deleted = 0;
        $scanned = 0;
        $seen = [];
        $clearedDirectories = [];

        foreach ($rules as $rule) {
            $path = (string) ($rule['path'] ?? '/profiles');
            $clearedDirectories[$path] = true;

            foreach ($this->filesIn($server, $path, (bool) ($rule['recursive'] ?? false)) as $entry) {
                $name = trim((string) ($entry['name'] ?? ''));

                if ($name === '' || !(($rule['matcher'])($name))) {
                    continue;
                }

                $targetPath = trim((string) ($entry['path'] ?? ''));

                if ($targetPath === '' || isset($seen[$targetPath])) {
                    continue;
                }
                $seen[$targetPath] = true;

                $logType = (string) ($rule['type'] ?? '');
                $retentionDays = (int) ($retentionByType[$logType] ?? 14);
                $timestamp = $this->timestampFromModified((string) ($entry['modified'] ?? ''));

                if ($timestamp === null) {
                    [$nameTimestamp] = $this->timestampAndTypeFromName($name);
                    $timestamp = $nameTimestamp;
                }

                if ($timestamp === null) {
                    continue;
                }

                $scanned++;
                $cutoff = $now - ($retentionDays * 86400);

                if ($timestamp >= $cutoff) {
                    continue;
                }

                if ($this->gateway->deletePath($server, $targetPath)) {
                    $deleted++;
                }
            }
        }

        $this->markRan($cacheKey);
        foreach (array_keys($clearedDirectories) as $directory) {
            $this->gateway->clearFileListingCache($server, $directory);
        }

        return [
            'status' => 'scrubbed',
            'forced' => $force,
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
            'dzserver_adm'   => '/^DayZServer_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.ADM$/i',
            'dzserver_rpt'   => '/^DayZServer_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.RPT$/i',
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

    /**
     * @return list<array{name:string,path:string,size:int,modified:string}>
     */
    private function filesIn(mixed $server, string $root, bool $recursive): array
    {
        $files = [];
        $pending = [rtrim($root, '/') ?: '/'];

        while ($pending !== []) {
            $directory = (string) array_shift($pending);

            foreach ($this->gateway->listDirectory($server, $directory) as $entry) {
                $name = trim((string) ($entry['name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $path = rtrim($directory, '/') . '/' . $name;

                if (($entry['directory'] ?? false) && $recursive) {
                    $pending[] = $path;
                    continue;
                }

                if (!($entry['file'] ?? false) || !$this->isLogFile($name)) {
                    continue;
                }

                $files[] = [
                    'name' => $name,
                    'path' => $path,
                    'size' => max(0, (int) ($entry['size'] ?? 0)),
                    'modified' => (string) ($entry['modified'] ?? ''),
                ];
            }
        }

        return $files;
    }

    private function isLogFile(string $name): bool
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return in_array($extension, self::LOG_EXTENSIONS, true);
    }

    private function timestampFromModified(string $modified): ?int
    {
        $modified = trim($modified);

        if ($modified === '') {
            return null;
        }

        $timestamp = strtotime($modified);

        return $timestamp === false ? null : $timestamp;
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
