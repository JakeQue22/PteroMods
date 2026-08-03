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
     * Copies a mod's `Extras/types.xml` into the active mission's `Types_Extra`
     * folder and registers it in `cfgeconomycore.xml`.
     */
    public function syncTypesExtraForMod(mixed $server, string $folderName, string $title = ''): bool
    {
        $folderName = trim($folderName);

        if ($folderName === '') {
            return false;
        }

        $sourcePath = $this->findTypesXmlSourcePath($server, $folderName);

        if ($sourcePath === '') {
            return false;
        }

        $typesXml = $this->gateway->readFile($server, $sourcePath);

        if ($typesXml === null || trim($typesXml) === '') {
            return false;
        }

        $missionPath = $this->activeMissionPath($server);

        if ($missionPath === '') {
            return false;
        }

        $modName = $this->sanitizeModName($title !== '' ? $title : ltrim($folderName, '@'));
        $destinationPath = $missionPath . '/Types_Extra/' . $modName . '.xml';

        if (!$this->gateway->writeFile($server, $destinationPath, $typesXml)) {
            return false;
        }

        return $this->appendMissionTypeEntry($server, $missionPath, $modName);
    }

    /**
     * Removes Types_Extra files and cfgeconomycore entries associated with a mod.
     */
    public function removeTypesExtraForMod(mixed $server, string $folderName, string $title = '', string $workshopId = ''): bool
    {
        $missionPath = $this->activeMissionPath($server);

        if ($missionPath === '') {
            return false;
        }

        $candidates = array_values(array_unique(array_filter([
            $this->sanitizeModNameOrEmpty($title),
            $this->sanitizeModNameOrEmpty(ltrim($folderName, '@/')),
            $this->sanitizeModNameOrEmpty($workshopId),
            // Mirror the primary name computed by syncTypesExtraForMod, including
            // its "WorkshopMod" fallback for mods whose title/folder sanitises to "".
            $this->sanitizeModName($title !== '' ? $title : ltrim($folderName, '@')),
        ], static fn (string $name): bool => $name !== '')));

        if ($candidates === []) {
            return false;
        }

        $changed = false;

        foreach ($candidates as $name) {
            $deleted = $this->gateway->deletePath($server, $missionPath . '/Types_Extra/' . $name . '.xml');
            $changed = $changed || $deleted;
        }

        $cfgPath = $missionPath . '/cfgeconomycore.xml';
        $cfg = $this->gateway->readFile($server, $cfgPath);

        if ($cfg === null || trim($cfg) === '') {
            return $changed;
        }

        $updated = $cfg;

        foreach ($candidates as $name) {
            $pattern = '/^\h*<file\h+name="Types_Extra\/' . preg_quote($name, '/') . '\.xml"\h+type="types"\h*\/>\h*\R?/mi';
            $updated = (string) preg_replace($pattern, '', $updated);
        }

        if ($updated !== $cfg) {
            $changed = $this->gateway->writeFile($server, $cfgPath, $updated) || $changed;
        }

        return $changed;
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

    private function sanitizeModName(string $value): string
    {
        $value = $this->sanitizeModNameOrEmpty($value);

        return $value !== '' ? $value : 'WorkshopMod';
    }

    private function sanitizeModNameOrEmpty(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9]+/', '', trim($value)) ?? '';
    }

    private function findTypesXmlSourcePath(mixed $server, string $folderName): string
    {
        $root = '/' . ltrim($folderName, '/');

        // Check the most common locations first to avoid unnecessary directory
        // listings: the mod root itself, and the canonical Extras/ subfolder.
        $directCandidates = [
            $root . '/Extras/types.xml',
            $root . '/extras/types.xml',
            $root . '/types.xml',
            $root . '/Types.xml',
            $root . '/db/types.xml',
            $root . '/db/Types.xml',
        ];

        foreach ($directCandidates as $candidate) {
            $contents = $this->gateway->readFile($server, $candidate);

            if ($contents !== null && trim($contents) !== '') {
                return $candidate;
            }
        }

        // Fall back to a BFS of the mod folder looking for any subdirectory
        // that contains a types.xml file (covers non-standard mod layouts).
        $queue = [[$root, 0]];
        $visited = [];

        while ($queue !== []) {
            [$path, $depth] = array_shift($queue);
            $normalized = strtolower($path);

            if (isset($visited[$normalized])) {
                continue;
            }

            $visited[$normalized] = true;

            if ($depth > 2) {
                continue;
            }

            foreach ($this->gateway->listDirectory($server, $path) as $entry) {
                if (!($entry['directory'] ?? false) || ($entry['name'] ?? '') === '') {
                    continue;
                }

                $child = rtrim($path, '/') . '/' . (string) $entry['name'];

                // Check for types.xml in every discovered subdirectory so that
                // mods which deviate from the Extras/ convention are handled.
                foreach (['types.xml', 'Types.xml'] as $typesFile) {
                    $candidate = $child . '/' . $typesFile;
                    $contents = $this->gateway->readFile($server, $candidate);

                    if ($contents !== null && trim($contents) !== '') {
                        return $candidate;
                    }
                }

                if ($depth < 2) {
                    $queue[] = [$child, $depth + 1];
                }
            }
        }

        return '';
    }

    private function activeMissionPath(mixed $server): string
    {
        $missions = array_filter(
            array_map(
                static fn (array $entry): string => $entry['directory'] ? (string) ($entry['name'] ?? '') : '',
                $this->gateway->listDirectory($server, '/mpmissions'),
            ),
            static fn (string $name): bool => $name !== '',
        );

        if ($missions === []) {
            return '';
        }

        foreach ($missions as $mission) {
            if (str_starts_with(strtolower($mission), 'dayzoffline.')) {
                return '/mpmissions/' . $mission;
            }
        }

        return '/mpmissions/' . array_values($missions)[0];
    }

    private function appendMissionTypeEntry(mixed $server, string $missionPath, string $modName): bool
    {
        $cfgPath = $missionPath . '/cfgeconomycore.xml';
        $cfg = $this->gateway->readFile($server, $cfgPath);

        if ($cfg === null || trim($cfg) === '') {
            return false;
        }

        $line = sprintf('<file name="Types_Extra/%s.xml" type="types" />', $modName);

        if (str_contains($cfg, $line)) {
            return true;
        }

        $replacement = $line . "\n";

        if (preg_match('/(<!--\s*Modded Types\s*-->)/i', $cfg) === 1) {
            $updated = (string) preg_replace('/(<!--\s*Modded Types\s*-->)/i', "$1\n" . $replacement, $cfg, 1);
        } elseif (str_contains($cfg, '</ce>')) {
            // Insert before the last </ce> so the entry is inside the <ce> element
            // that DayZ requires for <file> declarations.
            $pos = (int) strrpos($cfg, '</ce>');
            $updated = substr($cfg, 0, $pos) . $replacement . substr($cfg, $pos);
        } elseif (str_contains($cfg, '</economycore>')) {
            $updated = str_replace('</economycore>', $replacement . '</economycore>', $cfg);
        } else {
            $updated = rtrim($cfg) . "\n" . $replacement;
        }

        return $this->gateway->writeFile($server, $cfgPath, $updated);
    }
}
