<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

/**
 * Builds DayZ dashboard card data.
 *
 * Facts come from three sources: the panel database (limits, names), the Wings
 * daemon (power state and live resource usage), and the game server itself over
 * the Steam query protocol (map, players, version).
 */
final class DayZDashboardService
{
    /** DayZ's default slot count, used when the max player count is unknown. */
    private const DEFAULT_MAX_PLAYERS = 64;

    public function __construct(
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZServerQueryService $query = new DayZServerQueryService(),
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZWorkshopService $workshop = new DayZWorkshopService(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(mixed $server = null): array
    {
        $resolved = $this->context->resolve($server);
        $model = $resolved['model'];
        $installedMods = $this->workshop->installedMods($model);
        $live = $this->query->query($model);
        $details = $this->gateway->details($model);
        $usage = is_array($details['utilization'] ?? null) ? $details['utilization'] : [];

        $cpuLimit = $this->context->attribute($model, ['cpu', 'cpu_limit']);
        $memoryLimitMb = $this->intValue($model, ['memory', 'memory_limit']);
        $diskLimitMb = $this->intValue($model, ['disk', 'disk_limit']);
        $status = $this->resolveStatus($model, $live, $details);

        return [
            'server_name'          => $live['name'] ?? $resolved['name'],
            'server_id'            => $resolved['id'],
            'current_map'          => $this->formatMap($live['map'] ?? $this->context->attribute($model, ['map', 'current_map'])),
            'server_version'       => $live['version'] ?? $this->fallback($this->context->attribute($model, ['version', 'server_version'])),
            'installed_mods'       => $installedMods,
            'installed_mods_count' => count($installedMods),
            'enabled_mods_count'   => count(array_filter($installedMods, static fn (array $mod): bool => (bool) ($mod['enabled'] ?? false))),
            'player_count'         => $this->formatPlayerCount($live),
            'cpu'                  => $this->formatCpu($cpuLimit, $usage),
            'ram'                  => $this->formatMemory($memoryLimitMb, $usage),
            'disk'                 => $this->formatDisk($diskLimitMb, $usage),
            'uptime'               => $this->formatUptime($usage),
            'server_status'        => $status,
            'status_source'        => $this->statusSource($model, $live, $details),
            'connection_address'   => $this->query->connectionAddress($model) ?? 'Unknown',
            'query_endpoint'       => $live['endpoint'] ?? 'Unknown',
            'query_online'         => $live['online'],
        ];
    }

    /**
     * @param list<string> $keys
     */
    private function intValue(mixed $source, array $keys): ?int
    {
        $value = $this->context->attribute($source, $keys);

        return is_numeric($value) ? (int) $value : null;
    }

    private function fallback(string $value, string $fallback = 'N/A'): string
    {
        return $value !== '' ? $value : $fallback;
    }

    private function formatMap(?string $map): string
    {
        $map = trim((string) $map);

        if ($map === '') {
            return 'N/A';
        }

        // DayZ reports mission names such as "dayzOffline.chernarusplus".
        $known = [
            'chernarusplus' => 'Chernarus+',
            'chernarus'     => 'Chernarus',
            'enoch'         => 'Livonia',
            'livonia'       => 'Livonia',
            'sakhal'        => 'Sakhal',
            'namalsk'       => 'Namalsk',
            'deerisle'      => 'Deer Isle',
        ];

        $segments = explode('.', $map);
        $key = strtolower((string) end($segments));

        return $known[$key] ?? $map;
    }

    /**
     * Formats the player count as `players / max`, e.g. `12 / 64`.
     *
     * When the query fails (server offline, query port unreachable, etc.) the
     * player count is unknown rather than zero, but the card still needs a
     * number to show; `0 / 64` communicates "no known players, typical slot
     * count" instead of an unhelpful "N/A".
     *
     * @param array{online: bool, players: int|null, max_players: int|null} $live
     */
    public function formatPlayerCount(array $live): string
    {
        $players = $live['online'] && $live['players'] !== null ? $live['players'] : 0;
        $maxPlayers = $live['max_players'] !== null && $live['max_players'] > 0
            ? $live['max_players']
            : self::DEFAULT_MAX_PLAYERS;

        return $players . ' / ' . $maxPlayers;
    }

    /**
     * @param array<string, mixed> $usage
     */
    private function formatCpu(string $cpuLimit, array $usage): string
    {
        $limit = $cpuLimit === '' || $cpuLimit === '0' ? 'Unlimited' : rtrim($cpuLimit, '%') . '%';
        $current = $usage['cpu_absolute'] ?? null;

        if (!is_numeric($current)) {
            return $limit;
        }

        return sprintf('%.1f%% of %s', (float) $current, $limit);
    }

    /**
     * @param array<string, mixed> $usage
     */
    private function formatMemory(?int $limitMb, array $usage): string
    {
        return $this->formatUsage($limitMb, $usage['memory_bytes'] ?? null);
    }

    /**
     * @param array<string, mixed> $usage
     */
    private function formatDisk(?int $limitMb, array $usage): string
    {
        return $this->formatUsage($limitMb, $usage['disk_bytes'] ?? null);
    }

    private function formatUsage(?int $limitMb, mixed $usedBytes): string
    {
        $limit = $this->formatMegabytesLimit($limitMb);

        if (!is_numeric($usedBytes)) {
            return $limit;
        }

        return sprintf('%.1f GB of %s', ((float) $usedBytes) / 1073741824, $limit);
    }

    /**
     * @param array<string, mixed> $usage
     */
    private function formatUptime(array $usage): string
    {
        $uptime = $usage['uptime'] ?? null;

        if (!is_numeric($uptime) || (float) $uptime <= 0) {
            return 'N/A';
        }

        // Wings reports uptime in milliseconds.
        $seconds = (int) ((float) $uptime / 1000);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return sprintf('%dd %dh %dm', $days, $hours, $minutes);
        }

        return $hours > 0 ? sprintf('%dh %dm', $hours, $minutes) : sprintf('%dm', max($minutes, 1));
    }

    private function formatMegabytesLimit(?int $limitMb): string
    {
        if ($limitMb === null || $limitMb <= 0) {
            return 'Unlimited';
        }

        return sprintf('%.1f GB', $limitMb / 1024);
    }

    /**
     * Resolves the status shown on the dashboard.
     *
     * The `servers.status` column only describes installation/transfer states
     * and is null for a healthy server, so the live power state reported by
     * Wings takes precedence; the Steam query result is the last resort.
     *
     * @param array{online: bool}                                              $live
     * @param array{state: string, is_suspended: bool, utilization: array}|null $details
     */
    private function resolveStatus(mixed $model, array $live, ?array $details): string
    {
        $installState = $this->installState($model);

        if ($installState !== '') {
            return $installState;
        }

        $state = $details['state'] ?? '';

        if (is_string($state) && $state !== '') {
            return $state;
        }

        if ($live['online']) {
            return 'running';
        }

        if ($this->truthy($model, 'suspended') || (bool) ($details['is_suspended'] ?? false)) {
            return 'suspended';
        }

        return $model === null ? 'unknown' : 'offline';
    }

    /**
     * Installation, transfer, and suspension states stored in the panel.
     */
    private function installState(mixed $model): string
    {
        if ($this->truthy($model, 'suspended')) {
            return 'suspended';
        }

        foreach (['status', 'server_status'] as $key) {
            $status = strtolower($this->context->attribute($model, [$key]));

            if ($status === '') {
                continue;
            }

            return match ($status) {
                'restoring_backup' => 'restoring backup',
                'install_failed'   => 'install failed',
                default            => $status,
            };
        }

        return '';
    }

    /**
     * Explains where the status card value came from, for the page footnote.
     *
     * @param array{online: bool}                                              $live
     * @param array{state: string, is_suspended: bool, utilization: array}|null $details
     */
    private function statusSource(mixed $model, array $live, ?array $details): string
    {
        if ($this->installState($model) !== '') {
            return 'panel';
        }

        if (is_string($details['state'] ?? null) && $details['state'] !== '') {
            return 'daemon';
        }

        return $live['online'] ? 'steam query' : 'unavailable';
    }

    private function truthy(mixed $source, string $key): bool
    {
        $value = $this->context->rawAttribute($source, $key);

        return $value === true || $value === 1 || $value === '1';
    }
}
