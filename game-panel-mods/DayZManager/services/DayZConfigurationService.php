<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use PteroMods\Services\DayZ\ConfigurationCatalog;
use Throwable;

/**
 * Lists the configuration files that exist on a DayZ server and links every one
 * of them to the panel file manager.
 *
 * Files are discovered through the Pterodactyl daemon so the page shows the
 * real locations (server root, `config/`, the active mission folder, the BattlEye
 * and profile directories) instead of a fixed name list. Each entry carries a
 * deep link into the panel editor, which opens with syntax highlighting that
 * matches the file extension.
 */
final class DayZConfigurationService
{
    /** Directories scanned for configuration files, relative to the server root. */
    private const SCAN_DIRECTORIES = [
        '/'          => 'Server root',
        '/config'    => 'Config',
        '/profiles'  => 'Profiles',
        '/battleye'  => 'BattlEye',
        '/mpmissions' => 'Missions',
    ];

    /** Extensions considered configuration/content files. */
    private const CONFIG_EXTENSIONS = ['cfg', 'xml', 'txt', 'json', 'ini', 'conf', 'bat', 'sh', 'log', 'c'];

    private const MAX_FILES_PER_DIRECTORY = 200;

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly ConfigurationCatalog $catalog = new ConfigurationCatalog(),
    ) {
    }

    /**
     * Configuration files grouped by the directory they live in.
     *
     * @return list<array{label: string, path: string, browse_url: string, entries: list<array<string, mixed>>}>
     */
    public function groups(mixed $server, string $serverId): array
    {
        $model = is_object($server) ? $server : $this->context->resolve($server)['model'];
        $groups = [];

        foreach ($this->directories($model) as $directory => $label) {
            $entries = $this->entriesIn($model, $directory, $serverId);

            if ($entries === []) {
                continue;
            }

            $groups[] = [
                'label'      => $label,
                'path'       => $directory,
                'browse_url' => $this->browseUrl($serverId, $directory),
                'entries'    => $entries,
            ];
        }

        if ($groups === []) {
            $groups[] = [
                'label'      => 'Server root',
                'path'       => '/',
                'browse_url' => $this->browseUrl($serverId, '/'),
                'entries'    => array_map(
                    fn (string $path): array => $this->entry($path, 0, '', $serverId, false),
                    $this->defaultPaths(),
                ),
            ];
        }

        return $groups;
    }

    /**
     * Categorised view of the discovered files, used by the JSON API.
     *
     * @param list<string> $paths
     * @return array<string, list<string>>
     */
    public function files(array $paths): array
    {
        if ($paths === []) {
            $paths = $this->defaultPaths();
        }

        return $this->catalog->categorize($paths);
    }

    /**
     * Configuration files a DayZ server is expected to expose.
     *
     * Used as the browsing starting point when the daemon cannot be reached.
     *
     * @return list<string>
     */
    public function defaultPaths(): array
    {
        return [
            '/serverDZ.cfg',
            '/BEServer.cfg',
            '/messages.xml',
            '/priority.txt',
            '/ban.txt',
            '/whitelist.txt',
            '/settings.cfg',
            '/messages.cfg',
            '/admins.xml',
            '/SuperAdmins.txt',
        ];
    }

    /**
     * @return list<string>
     */
    public function editorFeatures(): array
    {
        return [
            'panel file editor with syntax highlighting',
            'download',
            'upload replacement',
            'rename and move',
            'server-side backups before saving',
            'version history',
            'search',
            'line numbers',
            'comments preserved',
        ];
    }

    /**
     * Writes a configuration file back to the server container, keeping a
     * backup of the previous contents.
     *
     * @return array<string, mixed>
     */
    public function save(string $path, string $content, mixed $server = null): array
    {
        $model = is_object($server) ? $server : $this->context->resolve($server)['model'];
        $previous = $model === null ? null : $this->gateway->readFile($model, $path);
        $backed = $previous === null ? false : $this->backup($path, $previous);
        $saved = $model !== null && $this->gateway->writeFile($model, $path, $content);

        return [
            'path'           => $path,
            'saved'          => $saved,
            'backup_created' => $backed,
            'bytes'          => strlen($content),
            'message'        => $saved
                ? 'Configuration file written to the server.'
                : 'The daemon did not accept the write request.',
        ];
    }

    /**
     * Scanned directories, including the mission folders and the profile
     * directory configured in the startup command.
     *
     * @return array<string, string>
     */
    private function directories(mixed $server): array
    {
        $directories = self::SCAN_DIRECTORIES;

        foreach ($this->gateway->listDirectory($server, '/mpmissions') as $entry) {
            if ($entry['directory'] && $entry['name'] !== '') {
                $path = '/mpmissions/' . $entry['name'];
                $directories[$path] = 'Mission · ' . $entry['name'];
                $directories[$path . '/db'] = 'Mission database · ' . $entry['name'];
            }
        }

        return $directories;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entriesIn(mixed $server, string $directory, string $serverId): array
    {
        $entries = [];

        foreach ($this->gateway->listDirectory($server, $directory) as $entry) {
            if (count($entries) >= self::MAX_FILES_PER_DIRECTORY) {
                break;
            }

            if (!$entry['file'] || $entry['name'] === '' || !$this->isConfigurationFile($entry['name'])) {
                continue;
            }

            $path = rtrim($directory, '/') . '/' . $entry['name'];
            $entries[] = $this->entry($path, $entry['size'], $entry['modified'], $serverId, true);
        }

        usort($entries, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $path, int $size, string $modified, string $serverId, bool $exists): array
    {
        $described = $this->catalog->describe($path);

        return $described + [
            'directory'  => rtrim(dirname($path), '/') ?: '/',
            'size'       => $this->formatBytes($size),
            'modified'   => $modified,
            'exists'     => $exists,
            'edit_url'   => $described['editable']
                ? $this->editUrl($serverId, $path)
                : $this->browseUrl($serverId, dirname($path)),
            'browse_url' => $this->browseUrl($serverId, dirname($path)),
        ];
    }

    private function isConfigurationFile(string $name): bool
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return in_array($extension, self::CONFIG_EXTENSIONS, true);
    }

    /**
     * Deep link into the panel file editor.
     */
    private function editUrl(string $serverId, string $path): string
    {
        return '/server/' . rawurlencode($serverId) . '/files/edit#' . $this->encodePath($path);
    }

    /**
     * Deep link into the panel file browser.
     */
    private function browseUrl(string $serverId, string $path): string
    {
        return '/server/' . rawurlencode($serverId) . '/files#' . $this->encodePath($path);
    }

    private function encodePath(string $path): string
    {
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');

        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function backup(string $path, string $content): bool
    {
        try {
            if (!class_exists('Illuminate\\Support\\Facades\\Schema')
                || !class_exists('Illuminate\\Support\\Facades\\DB')
                || !\Illuminate\Support\Facades\Schema::hasTable('dayz_configuration_backups')) {
                return false;
            }

            \Illuminate\Support\Facades\DB::table('dayz_configuration_backups')->insert([
                'path'       => $path,
                'content'    => $content,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
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
