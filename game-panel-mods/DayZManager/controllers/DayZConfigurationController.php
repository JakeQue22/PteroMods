<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZConfigurationService;
use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZServerContext;
use Throwable;

/**
 * Provides categorized DayZ configuration files for editing.
 */
final class DayZConfigurationController
{
    public function __construct(
        private readonly DayZConfigurationService $service = new DayZConfigurationService(),
        private readonly DayZPageRenderer $renderer = new DayZPageRenderer(),
        private readonly DayZServerContext $context = new DayZServerContext(),
    ) {
    }

    /**
     * Renders the configuration page, or returns the catalog for API requests.
     *
     * @param list<string> $paths
     * @return mixed
     */
    public function index(mixed $server = null, array $paths = [])
    {
        $resolved = $this->context->resolve($server);

        try {
            $data = [
                'files'           => $this->service->files($paths),
                'editor_features' => $this->service->editorFeatures(),
            ];
        } catch (Throwable $exception) {
            return $this->renderer->renderError($exception->getMessage(), 'configuration', $resolved['id'], $resolved['name']);
        }

        if ($this->context->expectsJson()) {
            return $data;
        }

        return $this->renderer->render('configuration', $data, 'configuration', $resolved['id'], $resolved['name']);
    }

    /**
     * @return array<string, mixed>
     */
    public function save(mixed $server = null, string $path = '', string $content = ''): array
    {
        $path = $path !== '' ? $path : $this->context->stringInput('path');
        $content = $content !== '' ? $content : (string) $this->context->input('content', '');

        return $this->service->save($path, $content);
    }
}
