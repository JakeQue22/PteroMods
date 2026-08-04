<?php

declare(strict_types=1);

namespace PteroMods\Services\DayZ;

/**
 * Builds DayZ launch parameters from ordered enabled mod folders.
 */
final class LaunchParameterBuilder
{
    /**
     * @param list<string> $orderedFolders
     */
    public function build(array $orderedFolders): string
    {
        $orderedFolders = array_values(array_filter($orderedFolders, static fn (string $folder): bool => $folder !== ''));

        if ($orderedFolders === []) {
            return '';
        }

        return '-mod=' . implode(';', $orderedFolders);
    }
}
