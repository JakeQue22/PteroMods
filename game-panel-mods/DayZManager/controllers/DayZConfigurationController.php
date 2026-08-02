<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZConfigurationService;

/**
 * Provides categorized DayZ configuration files for editing.
 */
final class DayZConfigurationController
{
    public function __construct(private readonly DayZConfigurationService $service = new DayZConfigurationService())
    {
    }

    /**
     * @param list<string> $paths
     * @return array<string, mixed>
     */
    public function index(array $paths = []): array
    {
        return [
            'files' => $this->service->files($paths),
            'editor_features' => $this->service->editorFeatures(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function save(string $path, string $content): array
    {
        return $this->service->save($path, $content);
    }
}
