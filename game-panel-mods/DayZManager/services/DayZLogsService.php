<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

/**
 * Discovers DayZ and supported-mod log files through Wings.
 */
final class DayZLogsService
{
    private const MAX_FILES = 500;

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
    ) {
    }

    /**
     * @return list<array{key:string,label:string,path:string,browse_url:string,entries:list<array<string,mixed>>}>
     */
    public function groups(mixed $server, string $serverId): array
    {
        $groups = [
            'errors' => $this->group('errors', 'Error Logs', '/profiles', $serverId),
            'server' => $this->group('server', 'Server Logs', '/profiles', $serverId),
            'admin' => $this->group('admin', 'Admin Logs', '/profiles/VPPAdminTools/Logging', $serverId),
            'codelock' => $this->group('codelock', 'Code Lock Logs', '/profiles/CodeLock/Logs', $serverId),
            'airdrop' => $this->group('airdrop', 'Airdrop Logs', '/profiles/Airdrop/Logs', $serverId),
        ];

        foreach ($this->filesIn($server, '/profiles', false) as $entry) {
            $key = $this->isErrorLog($entry['name']) ? 'errors' : 'server';
            $groups[$key]['entries'][] = $this->entry($entry, $serverId);
        }

        foreach (['admin', 'codelock', 'airdrop'] as $key) {
            foreach ($this->filesIn($server, $groups[$key]['path'], true) as $entry) {
                $groups[$key]['entries'][] = $this->entry($entry, $serverId);
            }
        }

        foreach ($groups as &$group) {
            usort($group['entries'], static function (array $left, array $right): int {
                $modified = strcmp((string) ($right['modified'] ?? ''), (string) ($left['modified'] ?? ''));

                return $modified !== 0 ? $modified : strcasecmp((string) $left['path'], (string) $right['path']);
            });
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * @return array{key:string,label:string,path:string,browse_url:string,entries:list<array<string,mixed>>}
     */
    private function group(string $key, string $label, string $path, string $serverId): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'path' => $path,
            'browse_url' => $this->browseUrl($serverId, $path),
            'entries' => [],
        ];
    }

    /**
     * @return list<array{name:string,path:string,size:int,modified:string}>
     */
    private function filesIn(mixed $server, string $root, bool $recursive): array
    {
        $files = [];
        $pending = [rtrim($root, '/') ?: '/'];

        while ($pending !== [] && count($files) < self::MAX_FILES) {
            $directory = array_shift($pending);

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

                if (count($files) >= self::MAX_FILES) {
                    break;
                }
            }
        }

        return $files;
    }

    private function isLogFile(string $name): bool
    {
        return in_array(strtolower((string) pathinfo($name, PATHINFO_EXTENSION)), ['log', 'rpt'], true);
    }

    private function isErrorLog(string $name): bool
    {
        return preg_match('/(?:crash|error|script)/i', $name) === 1;
    }

    /**
     * @param array{name:string,path:string,size:int,modified:string} $file
     * @return array<string, mixed>
     */
    private function entry(array $file, string $serverId): array
    {
        return $file + [
            'directory' => rtrim(dirname($file['path']), '/') ?: '/',
            'size_display' => $this->formatBytes($file['size']),
            'edit_url' => '/server/' . rawurlencode($serverId) . '/files/edit#' . $this->encodePath($file['path']),
        ];
    }

    private function browseUrl(string $serverId, string $path): string
    {
        return '/server/' . rawurlencode($serverId) . '/files#' . $this->encodePath($path);
    }

    private function encodePath(string $path): string
    {
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');

        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = min((int) floor(log((float) $bytes, 1024)), count($units) - 1);

        return sprintf('%.1f %s', $bytes / (1024 ** $power), $units[$power]);
    }
}
