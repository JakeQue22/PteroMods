<?php

declare(strict_types=1);

namespace GamePanelMods\ArkManager\Services;

/**
 * Supplies installable module metadata for Ark Manager.
 */
final class ArkManagerService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return [
            'module' => 'Ark Manager',
            'supports' => ['ark'],
            'capabilities' => ['dashboard', 'settings', 'api'],
            'status' => 'available',
        ];
    }
}
