<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZEggDetector;
use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZServerContext;

/**
 * Serves the navigation-tab integration: the small script the panel layouts
 * load, and the endpoint it uses to decide whether a server is a DayZ server.
 */
final class DayZTabController
{
    public function __construct(
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZEggDetector $detector = new DayZEggDetector(),
        private readonly DayZPageRenderer $renderer = new DayZPageRenderer(),
    ) {
    }

    /**
     * Tells the navigation script whether to show the DayZ Manager tab.
     *
     * @return array{supported: bool, label: string, url: string, server_id: string}
     */
    public function status(mixed $server = null): array
    {
        $resolved = $this->context->resolve($server);

        return [
            'supported' => $this->detector->supports($resolved['model']),
            'label'     => 'DayZ Manager',
            'url'       => $this->renderer->basePath($resolved['id']),
            'server_id' => $resolved['id'],
        ];
    }

    /**
     * Serves the navigation script that injects the tab into the panel.
     *
     * @return mixed
     */
    public function script()
    {
        $file = __DIR__ . '/../assets/panel-tab.js';
        $script = is_file($file) ? (string) file_get_contents($file) : '';

        if (!function_exists('response')) {
            return $script;
        }

        return response($script, 200, [
            'Content-Type'  => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
