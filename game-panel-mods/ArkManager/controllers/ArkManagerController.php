<?php

declare(strict_types=1);

namespace GamePanelMods\ArkManager\Controllers;

use GamePanelMods\ArkManager\Services\ArkManagerService;

/**
 * Returns the overview payload for the Ark Manager module.
 */
final class ArkManagerController
{
    public function __construct(private readonly ArkManagerService $service = new ArkManagerService())
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
