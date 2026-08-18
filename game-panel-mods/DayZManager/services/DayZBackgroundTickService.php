<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/** Runs time-sensitive jobs that must not depend on a DayZ Manager page load. */
final class DayZBackgroundTickService
{
    public function __construct(
        private readonly DayZServerService $servers = new DayZServerService(),
        private readonly DayZEggDetector $detector = new DayZEggDetector(),
        private readonly DayZCacheWarmService $warmer = new DayZCacheWarmService(),
    ) {
    }

    public function tick(): void
    {
        foreach ($this->scheduledServers() as $server) {
            try {
                $this->servers->tickRestartSchedule($server);
            } catch (Throwable) {
                // One unreachable or deleted server must not prevent the
                // remaining schedules from being processed.
            }
        }

        // Keep the existing non-time-critical cache and maintenance jobs.
        $this->warmer->tick(null);
    }

    /** @return list<mixed> */
    private function scheduledServers(): array
    {
        if (!class_exists('Illuminate\Support\Facades\DB')
            || !class_exists('Illuminate\Support\Facades\Schema')
            || !class_exists('Pterodactyl\Models\Server')) {
            return [];
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('dayz_restart_schedules')) {
                return [];
            }

            $query = \Illuminate\Support\Facades\DB::table('dayz_restart_schedules')
                ->where('enabled', true);

            if (\Illuminate\Support\Facades\Schema::hasColumn('dayz_restart_schedules', 'timed_restart_at')) {
                $query->orWhereNotNull('timed_restart_at');
            }

            $identifiers = $query->pluck('server_id')
                ->map(static fn (mixed $id): string => trim((string) $id))
                ->filter(static fn (string $id): bool => $id !== '')
                ->unique()
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }

        $result = [];

        foreach ($identifiers as $identifier) {
            try {
                $server = \Pterodactyl\Models\Server::query()
                    ->with(['egg', 'node', 'allocation', 'allocations'])
                    ->where('uuid', $identifier)
                    ->orWhere('uuidShort', $identifier)
                    ->when(ctype_digit($identifier), static function ($query) use ($identifier): void {
                        $query->orWhere('id', (int) $identifier);
                    })
                    ->first();

                if ($server !== null && $this->detector->supports($server)) {
                    $result[] = $server;
                }
            } catch (Throwable) {
                // Ignore stale schedule rows and continue.
            }
        }

        return $result;
    }
}
