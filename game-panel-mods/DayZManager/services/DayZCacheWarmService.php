<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Periodic background cache warmer for DayZ pages.
 */
final class DayZCacheWarmService
{
    private const DEFAULT_WARM_INTERVAL_SECONDS = 60;
    private const MIN_WARM_INTERVAL_SECONDS = 5;
    private const MAX_WARM_INTERVAL_SECONDS = 3600;
    private const MAX_SERVERS_PER_TICK = 8;

    public function __construct(
        private readonly DayZEggDetector $detector = new DayZEggDetector(),
        private readonly DayZDashboardService $dashboard = new DayZDashboardService(),
        private readonly DayZWorkshopService $workshop = new DayZWorkshopService(),
        private readonly DayZServerService $server = new DayZServerService(),
        private readonly DayZServerQueryService $query = new DayZServerQueryService(),
        private readonly DayZConfigurationService $configuration = new DayZConfigurationService(),
        private readonly DayZPlayerService $players = new DayZPlayerService(),
        private readonly DayZPersistencePlayerService $persistence = new DayZPersistencePlayerService(),
        private readonly DayZVppAdminService $vppAdmin = new DayZVppAdminService(),
        private readonly DayZGiveMoneyService $giveMoney = new DayZGiveMoneyService(),
        private readonly DayZLiveMapService $liveMap = new DayZLiveMapService(),
        private readonly DayZMapMarkerService $mapMarkers = new DayZMapMarkerService(),
        private readonly DayZBackupService $backups = new DayZBackupService(),
        private readonly DayZManagerSettingsService $settings = new DayZManagerSettingsService(),
        private readonly DayZProfileLogScrubService $logScrub = new DayZProfileLogScrubService(),
    ) {
    }

    public function tick(mixed $priorityServer = null): void
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return;
        }

        $intervalSeconds = $this->warmIntervalSeconds();
        $runKey = 'pteromods.dayz.cache_warm.last_run';
        $lockKey = $runKey . '.lock';
        $now = time();

        try {
            $lastRun = (int) (\Illuminate\Support\Facades\Cache::get($runKey) ?? 0);

            if (($now - $lastRun) < $intervalSeconds
                || !\Illuminate\Support\Facades\Cache::add($lockKey, 1, $intervalSeconds)) {
                return;
            }

            \Illuminate\Support\Facades\Cache::put($runKey, $now, $intervalSeconds * 5);
        } catch (Throwable) {
            return;
        }

        // Resolve the server list synchronously (cheap DB query) so the
        // priority server is always included even if the shutdown function runs
        // in a different execution context.
        $serversToWarm = $this->serversToWarm($priorityServer);

        // Defer the actual warming work to run after the response has been sent
        // so tab loads always return stale cache immediately rather than
        // blocking while cold-cache service calls complete.
        register_shutdown_function(function () use ($serversToWarm, $lockKey): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            try {
                $this->warmGlobalCaches();

                foreach ($serversToWarm as $server) {
                    $this->warmServerCaches($server);
                }
            } catch (Throwable) {
                // Best-effort warming only.
            } finally {
                try {
                    \Illuminate\Support\Facades\Cache::forget($lockKey);
                } catch (Throwable) {
                    // Best-effort lock cleanup.
                }
            }
        });
    }

    /**
     * Forces cache invalidation and immediate cache re-population for all
     * DayZ Manager data points that are warmed in the background.
     *
     * @return array<string, int>
     */
    public function forceRefreshAll(mixed $priorityServer = null): array
    {
        $serversToWarm = $this->serversToWarm($priorityServer);
        $serverCount = count($serversToWarm);

        $this->forgetGlobalCacheKeys();

        foreach ($serversToWarm as $server) {
            $this->forgetServerCacheKeys($server);
        }

        $this->warmGlobalCaches();

        foreach ($serversToWarm as $server) {
            $this->warmServerCaches($server);
        }

        return [
            'servers_warmed' => $serverCount,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function serversToWarm(mixed $priorityServer): array
    {
        $servers = [];

        if ($priorityServer !== null && $this->detector->supports($priorityServer)) {
            $servers[] = $priorityServer;
        }

        if (!class_exists('Pterodactyl\\Models\\Server')) {
            return $servers;
        }

        try {
            $rows = \Pterodactyl\Models\Server::query()
                ->with(['egg', 'node', 'allocation', 'allocations'])
                ->limit(self::MAX_SERVERS_PER_TICK)
                ->get()
                ->all();
        } catch (Throwable) {
            return $servers;
        }

        foreach ($rows as $row) {
            if (!$this->detector->supports($row)) {
                continue;
            }

            $id = (string) ($this->serverId($row));

            if ($id === '') {
                continue;
            }

            $exists = false;

            foreach ($servers as $server) {
                if ((string) $this->serverId($server) === $id) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $servers[] = $row;
            }
        }

        return $servers;
    }

    private function serverId(mixed $server): string
    {
        if (is_array($server)) {
            return (string) ($server['id'] ?? '');
        }

        if (!is_object($server)) {
            return '';
        }

        try {
            if (method_exists($server, 'getAttribute')) {
                $value = $server->getAttribute('id');
                if ($value !== null) {
                    return (string) $value;
                }
            }

            return isset($server->id) ? (string) $server->id : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function warmIntervalSeconds(): int
    {
        $configured = (int) $this->settings->get('cache_fetch_timer_seconds', self::DEFAULT_WARM_INTERVAL_SECONDS);

        return max(self::MIN_WARM_INTERVAL_SECONDS, min(self::MAX_WARM_INTERVAL_SECONDS, $configured));
    }

    private function warmGlobalCaches(): void
    {
        $this->settings->all();
        $this->players->allLists();
    }

    private function warmServerCaches(mixed $server): void
    {
        $this->dashboard->dashboard($server);
        $this->workshop->installedMods($server);
        $this->workshop->settings($server);
        $this->server->launchParameters($server);
        $this->server->tickRestartSchedule($server);
        $this->query->query($server);
        $this->configuration->groups($server, $this->serverId($server));
        $snapshot = $this->liveMap->snapshot($server);
        $mapName = trim((string) ($snapshot['map'] ?? 'ChernarusPlus'));
        $this->persistence->snapshot($server, $mapName !== '' ? $mapName : 'ChernarusPlus');
        $this->vppAdmin->list($server);
        $this->giveMoney->pending($server);
        $this->mapMarkers->markers($server, $mapName !== '' ? $mapName : 'ChernarusPlus');
        $this->backups->list($server);
        $this->logScrub->tick($server, true);
    }

    private function forgetGlobalCacheKeys(): void
    {
        $this->forgetCacheKeys([
            'pteromods.dayz.settings.all',
            'pteromods.dayz.settings.all.lock',
            'pteromods.dayz.players.ban',
            'pteromods.dayz.players.ban.lock',
            'pteromods.dayz.players.whitelist',
            'pteromods.dayz.players.whitelist.lock',
            'pteromods.dayz.players.priority',
            'pteromods.dayz.players.priority.lock',
            'pteromods.dayz.cache_warm.last_run',
            'pteromods.dayz.cache_warm.last_run.lock',
        ]);
    }

    private function forgetServerCacheKeys(mixed $server): void
    {
        $serverId = $this->serverId($server);

        if ($serverId === '') {
            return;
        }

        $hash = md5($serverId);
        $markerMaps = ['chernarusplus', 'livonia', 'enoch', 'sakhal', 'dayzoffline.chernarusplus', 'dayzoffline.enoch', 'dayzoffline.sakhal'];
        $markerKeys = array_map(
            static fn (string $map): string => 'pteromods.dayz.live_map.markers.' . md5($serverId . '|' . $map),
            $markerMaps
        );

        $this->forgetCacheKeys(array_merge([
            'pteromods.dayz.dashboard.stats.' . $hash,
            'pteromods.dayz.dashboard.buildid.' . $hash,
            'pteromods.dayz.dashboard.offline_crash.' . $hash,
            'pteromods.dayz.workshop.installed_mods.' . $hash,
            'pteromods.dayz.workshop.installed_mods.' . $hash . '.lock',
            'pteromods.dayz.workshop.stats.' . $hash,
            'pteromods.dayz.workshop.stats.' . $hash . '.lock',
            'pteromods.dayz.server.launch.' . $hash,
            'pteromods.dayz.server.launch.' . $hash . '.lock',
            'pteromods.dayz.persistence.snapshot.' . md5($serverId . '|chernarusplus'),
            'pteromods.dayz.persistence.snapshot.' . md5($serverId . '|livonia'),
            'pteromods.dayz.persistence.snapshot.' . md5($serverId . '|enoch'),
            'pteromods.dayz.persistence.snapshot.' . md5($serverId . '|sakhal'),
            'pteromods.dayz.persistence.snapshot.' . md5($serverId . '|dayzoffline.chernarusplus'),
            'pteromods.dayz.persistence.snapshot.' . md5($serverId . '|dayzoffline.enoch'),
            'pteromods.dayz.persistence.snapshot.' . md5($serverId . '|dayzoffline.sakhal'),
            'pteromods.dayz.vpp_admins.' . $hash,
            'pteromods.dayz.vpp_admins.' . $hash . '.lock',
            'pteromods.dayz.give_money.pending.' . $hash,
            'pteromods.dayz.give_money.pending.' . $hash . '.lock',
            'pteromods.dayz.live_map.snapshot.' . $hash,
            'pteromods.dayz.backups.list.' . $hash,
            'pteromods.dayz.backups.list.' . $hash . '.lock',
            'pteromods.dayz.backup.last_auto.' . $hash,
            'pteromods.dayz.profile_log_scrub.last_run.' . $hash,
        ], $markerKeys));

        $this->query->clearCache($server);
    }

    /**
     * @param list<string> $keys
     */
    private function forgetCacheKeys(array $keys): void
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return;
        }

        foreach ($keys as $key) {
            try {
                \Illuminate\Support\Facades\Cache::forget($key);
            } catch (Throwable) {
                // Best-effort cache invalidation only.
            }
        }
    }
}
