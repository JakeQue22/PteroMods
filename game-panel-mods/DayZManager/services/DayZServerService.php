<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use PteroMods\Services\DayZ\LaunchParameterBuilder;

/**
 * Server-level controls: restart scheduling and launch parameter preview.
 */
final class DayZServerService
{
    /**
     * @return array<string, mixed>
     */
    public function restart(string $reason = ''): array
    {
        return [
            'status'     => 'queued',
            'action'     => 'restart',
            'reason'     => $reason,
            'queued_at'  => date('c'),
        ];
    }

    /**
     * @param list<string> $enabledFolders  Ordered folder names of enabled mods.
     * @return array<string, mixed>
     */
    public function launchParameters(array $enabledFolders): array
    {
        $parameters = (new LaunchParameterBuilder())->build($enabledFolders);

        return [
            'launch_parameters' => $parameters,
            'mod_count'         => count(array_filter($enabledFolders, static fn (string $f): bool => $f !== '')),
        ];
    }
}
