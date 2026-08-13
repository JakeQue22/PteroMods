<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Periodic background cache warmer for DayZ pages.
 */
final class DayZCacheWarmService
{
    private const WARM_INTERVAL_SECONDS = 60;
    private const MAX_SERVERS_PER_TICK = 8;

    public function __construct(
        private readonly DayZEggDetector $detector = new DayZEggDetector(),
        private readonly DayZDashboardService $dashboard = new DayZDashboardService(),
        private readonly DayZWorkshopService $workshop = new DayZWorkshopService(),
        private readonly DayZServerService $server = new DayZServerService(),
        private readonly DayZServerQueryService $query = new DayZServerQueryService(),
        private readonly DayZPlayerService $players = new DayZPlayerService(),
        private readonly DayZProfileLogScrubService $logScrub = new DayZProfileLogScrubService(),
    ) {
    }

    public function tick(mixed $priorityServer = null): void
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return;
        }

        $runKey = 'pteromods.dayz.cache_warm.last_run';
        $lockKey = $runKey . '.lock';
        $now = time();

        try {
            $lastRun = (int) (\Illuminate\Support\Facades\Cache::get($runKey) ?? 0);

            if (($now - $lastRun) < self::WARM_INTERVAL_SECONDS
                || !\Illuminate\Support\Facades\Cache::add($lockKey, 1, self::WARM_INTERVAL_SECONDS)) {
                return;
            }

            \Illuminate\Support\Facades\Cache::put($runKey, $now, self::WARM_INTERVAL_SECONDS * 5);
        } catch (Throwable) {
            return;
        }

        try {
            foreach ($this->serversToWarm($priorityServer) as $server) {
                $this->dashboard->dashboard($server);
                $this->workshop->installedMods($server);
                $this->workshop->settings($server);
                $this->server->launchParameters($server);
                $this->query->query($server);
                $this->logScrub->tick($server);
            }

            $this->players->allLists();
        } catch (Throwable) {
            // Best-effort warming only.
        } finally {
            try {
                \Illuminate\Support\Facades\Cache::forget($lockKey);
            } catch (Throwable) {
                // Best-effort lock cleanup.
            }
        }
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
}
