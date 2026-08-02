<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZServerService;

/**
 * Handles server restart and launch parameter preview.
 */
final class DayZServerController
{
    public function __construct(private readonly DayZServerService $service = new DayZServerService())
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function restart(string $reason = ''): array
    {
        return $this->service->restart($reason);
    }

    /**
     * @param list<string> $enabledFolders
     * @return array<string, mixed>
     */
    public function launchParameters(array $enabledFolders = []): array
    {
        return $this->service->launchParameters($enabledFolders);
    }
}
