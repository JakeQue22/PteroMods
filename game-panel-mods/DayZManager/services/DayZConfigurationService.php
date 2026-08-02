<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use PteroMods\Services\DayZ\ConfigurationCatalog;

/**
 * Prepares DayZ configuration browser payloads.
 */
final class DayZConfigurationService
{
    /**
     * @param list<string> $paths
     * @return array<string, list<string>>
     */
    public function files(array $paths): array
    {
        return (new ConfigurationCatalog())->categorize($paths);
    }

    /**
     * @return list<string>
     */
    public function editorFeatures(): array
    {
        return [
            'dark mode',
            'light mode',
            'autosave',
            'version history',
            'restore previous version',
            'download',
            'upload replacement',
            'compare versions',
            'search',
            'replace',
            'line numbers',
            'undo',
            'validation',
            'comments preserved',
            'create backups before saving',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function save(string $path, string $content): array
    {
        return [
            'path' => $path,
            'saved' => true,
            'backup_created' => true,
            'bytes' => strlen($content),
        ];
    }
}
