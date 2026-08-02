<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Builds DayZ dashboard card data.
 */
final class DayZDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function dashboard(mixed $server = null): array
    {
        $resolved = $this->resolveServerContext($server);
        $name = $this->stringValue($resolved, ['name', 'server_name'], 'DayZ Server');
        $installedMods = $this->resolveInstalledModCount();

        $cpuLimit = $this->stringValue($resolved, ['cpu', 'cpu_limit']);
        $memoryLimitMb = $this->intValue($resolved, ['memory', 'memory_limit']);
        $diskLimitMb = $this->intValue($resolved, ['disk', 'disk_limit']);

        return [
            'server_name' => $name,
            'current_map' => $this->stringValue($resolved, ['map', 'current_map'], 'Unknown'),
            'server_version' => $this->stringValue($resolved, ['version', 'server_version'], 'Unknown'),
            'installed_mods' => $installedMods,
            'player_count' => $this->stringValue($resolved, ['player_count'], 'Unknown'),
            'cpu' => $this->formatCpu($cpuLimit),
            'ram' => $this->formatMegabytesLimit($memoryLimitMb),
            'disk' => $this->formatMegabytesLimit($diskLimitMb),
            'server_status' => $this->resolveStatus($resolved),
        ];
    }

    private function resolveServerContext(mixed $server): mixed
    {
        if ($server !== null && $server !== '') {
            if (is_object($server) || is_array($server)) {
                return $server;
            }

            $resolvedModel = $this->resolveServerModel((string) $server);

            return $resolvedModel ?? ['server_name' => (string) $server];
        }

        if (!function_exists('request')) {
            return null;
        }

        $request = request();

        if (!is_object($request) || !method_exists($request, 'route')) {
            return null;
        }

        $routeServer = $request->route('server');

        if (is_string($routeServer)) {
            $resolvedModel = $this->resolveServerModel($routeServer);

            return $resolvedModel ?? ['server_name' => $routeServer];
        }

        return $routeServer;
    }

    private function resolveInstalledModCount(): int
    {
        if (class_exists('Illuminate\\Support\\Facades\\Schema')
            && class_exists('Illuminate\\Support\\Facades\\DB')) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('dayz_mods')) {
                    return (int) \Illuminate\Support\Facades\DB::table('dayz_mods')->count();
                }
            } catch (Throwable) {
                // Fall back to in-memory fixture data.
            }
        }

        return count((new DayZWorkshopService())->installedMods());
    }

    private function resolveServerModel(string $identifier): mixed
    {
        if (!class_exists('Pterodactyl\\Models\\Server')) {
            return null;
        }

        try {
            $query = \Pterodactyl\Models\Server::query();

            return $query
                ->where('uuidShort', $identifier)
                ->orWhere('uuid', $identifier)
                ->orWhere('id', $identifier)
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $keys
     */
    private function stringValue(mixed $source, array $keys, string $fallback = ''): string
    {
        $value = $this->valueByKeys($source, $keys);

        if ($value === null) {
            return $fallback;
        }

        if (is_scalar($value)) {
            $string = trim((string) $value);
            return $string !== '' ? $string : $fallback;
        }

        return $fallback;
    }

    /**
     * @param list<string> $keys
     */
    private function intValue(mixed $source, array $keys): ?int
    {
        $value = $this->valueByKeys($source, $keys);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function formatCpu(string $cpuLimit): string
    {
        if ($cpuLimit === '') {
            return 'Unknown';
        }

        if ($cpuLimit === '0') {
            return 'Unlimited';
        }

        return rtrim($cpuLimit, '%') . '%';
    }

    private function formatMegabytesLimit(?int $limitMb): string
    {
        if ($limitMb === null) {
            return 'Unknown';
        }

        if ($limitMb <= 0) {
            return 'Unlimited';
        }

        return sprintf('Unknown / %.1f GB', $limitMb / 1024);
    }

    private function resolveStatus(mixed $source): string
    {
        foreach (['status', 'state', 'server_status'] as $key) {
            $status = strtolower($this->stringValue($source, [$key]));
            if ($status !== '') {
                return $status;
            }
        }

        if ($this->truthyValue($source, 'suspended')) {
            return 'suspended';
        }

        if ($this->truthyValue($source, 'installed')) {
            return 'running';
        }

        return 'unknown';
    }

    private function truthyValue(mixed $source, string $key): bool
    {
        $value = $this->valueByKeys($source, [$key]);
        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * @param list<string> $keys
     */
    private function valueByKeys(mixed $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($source) && array_key_exists($key, $source)) {
                return $source[$key];
            }

            if (is_object($source)) {
                if (isset($source->{$key}) || property_exists($source, $key)) {
                    return $source->{$key};
                }

                if (method_exists($source, 'getAttribute')) {
                    $attribute = $source->getAttribute($key);
                    if ($attribute !== null) {
                        return $attribute;
                    }
                }

                $getter = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
                if (method_exists($source, $getter)) {
                    return $source->{$getter}();
                }
            }
        }

        return null;
    }
}
