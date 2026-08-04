<?php

declare(strict_types=1);

namespace GamePanelMods\MinecraftManager\Controllers;

use GamePanelMods\MinecraftManager\Services\MinecraftManagerService;

/**
 * Returns the overview payload for the Minecraft Manager module.
 */
final class MinecraftManagerController
{
    public function __construct(private readonly MinecraftManagerService $service = new MinecraftManagerService())
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function index(): array
    {
        return $this->service->overview();
    }
}
