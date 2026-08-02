<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Builds DayZ dashboard card data.
 */
final class DayZDashboardService
{
    public function __construct(
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZServerQueryService $query = new DayZServerQueryService(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(mixed $server = null): array
    {
        $resolved = $this->context->resolve($server);
        $model = $resolved['model'];
        $installedMods = $this->resolveInstalledMods();
        $live = $this->query->query($model);

        $cpuLimit = $this->context->attribute($model, ['cpu', 'cpu_limit']);
        $memoryLimitMb = $this->intValue($model, ['memory', 'memory_limit']);
        $diskLimitMb = $this->intValue($model, ['disk', 'disk_limit']);

        return [
            'server_name'          => $live['name'] ?? $resolved['name'],
            'server_id'            => $resolved['id'],
            'current_map'          => $this->formatMap($live['map'] ?? $this->context->attribute($model, ['map', 'current_map'])),
            'server_version'       => $live['version'] ?? $this->fallback($this->context->attribute($model, ['version', 'server_version'])),
            'installed_mods'       => $installedMods,
            'installed_mods_count' => count($installedMods),
            'player_count'         => $this->formatPlayerCount($live),
            'cpu'                  => $this->formatCpu($cpuLimit),
            'ram'                  => $this->formatMegabytesLimit($memoryLimitMb),
            'disk'                 => $this->formatMegabytesLimit($diskLimitMb),
            'server_status'        => $this->resolveStatus($model, $live),
            'query_endpoint'       => $live['endpoint'] ?? 'Unknown',
            'query_online'         => $live['online'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveInstalledMods(): array
    {
        try {
            if (class_exists('Illuminate\\Support\\Facades\\Schema')
                && class_exists('Illuminate\\Support\\Facades\\DB')) {
                if (\Illuminate\Support\Facades\Schema::hasTable('dayz_mods')) {
                    /** @var list<array<string, mixed>> $rows */
                    $rows = \Illuminate\Support\Facades\DB::table('dayz_mods')->get()->toArray();
                    if ($rows !== []) {
                        return array_map(static fn ($row): array => (array) $row, $rows);
                    }
                }
            }
        } catch (Throwable) {
            // Fall back to in-memory fixture data.
        }

        return (new DayZWorkshopService())->installedMods();
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
     * @param array{online: bool, players: int|null, max_players: int|null} $live
     */
    private function formatPlayerCount(array $live): string
    {
        if (!$live['online'] || $live['players'] === null) {
            return 'N/A';
        }

        if ($live['max_players'] === null || $live['max_players'] <= 0) {
            return (string) $live['players'];
        }

        return $live['players'] . ' / ' . $live['max_players'];
    }

    private function formatCpu(string $cpuLimit): string
    {
        if ($cpuLimit === '' || $cpuLimit === '0') {
            return 'Unlimited';
        }

        return rtrim($cpuLimit, '%') . '%';
    }

    private function formatMegabytesLimit(?int $limitMb): string
    {
        if ($limitMb === null || $limitMb <= 0) {
            return 'Unlimited';
        }

        return sprintf('%.1f GB', $limitMb / 1024);
    }

    /**
     * @param array{online: bool} $live
     */
    private function resolveStatus(mixed $model, array $live): string
    {
        foreach (['status', 'state', 'server_status'] as $key) {
            $status = strtolower($this->context->attribute($model, [$key]));

            if ($status !== '') {
                return match ($status) {
                    'restoring_backup' => 'restoring backup',
                    'install_failed'   => 'install failed',
                    default            => $status,
                };
            }
        }

        if ($this->truthy($model, 'suspended')) {
            return 'suspended';
        }

        if ($live['online']) {
            return 'running';
        }

        return $model === null ? 'unknown' : 'offline';
    }

    private function truthy(mixed $source, string $key): bool
    {
        $value = $this->context->rawAttribute($source, $key);

        return $value === true || $value === 1 || $value === '1';
    }
}
