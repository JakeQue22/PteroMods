<?php

declare(strict_types=1);

namespace GamePanelMods\MinecraftManager\Services;

/**
 * Supplies installable module metadata for Minecraft Manager.
 */
final class MinecraftManagerService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return [
            'module' => 'Minecraft Manager',
            'supports' => ['minecraft'],
            'capabilities' => ['dashboard', 'settings', 'api'],
            'status' => 'available',
        ];
    }
}
