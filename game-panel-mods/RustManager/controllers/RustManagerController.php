<?php

declare(strict_types=1);

namespace GamePanelMods\RustManager\Controllers;

use GamePanelMods\RustManager\Services\RustManagerService;

/**
 * Returns the overview payload for the Rust Manager module.
 */
final class RustManagerController
{
    public function __construct(private readonly RustManagerService $service = new RustManagerService())
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
