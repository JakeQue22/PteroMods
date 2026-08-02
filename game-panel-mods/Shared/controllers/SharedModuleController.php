<?php

declare(strict_types=1);

namespace GamePanelMods\Shared\Controllers;

use GamePanelMods\Shared\Services\SharedModuleService;

/**
 * Provides the administration index payload for discovered modules.
 */
final class SharedModuleController
{
    public function __construct(private readonly SharedModuleService $service = new SharedModuleService())
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function index(): array
    {
        return [
            'title' => 'Administration → Game Panel Mods',
            'modules' => $this->service->catalog(),
        ];
    }
}
