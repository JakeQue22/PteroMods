<?php

declare(strict_types=1);

namespace GamePanelMods\RustManager\Services;

/**
 * Supplies installable module metadata for Rust Manager.
 */
final class RustManagerService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return [
            'module' => 'Rust Manager',
            'supports' => ['rust'],
            'capabilities' => ['dashboard', 'settings', 'api'],
            'status' => 'available',
        ];
    }
}
