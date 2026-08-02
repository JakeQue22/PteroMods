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
            $clientId = $this->context->clientIdentifier($resolved['model'], $resolved['id']);
            $groups = $this->service->groups($resolved['model'], $clientId);
            $paths = $paths !== [] ? $paths : $this->pathsIn($groups);

            $data = [
                'client_id'       => $clientId,
                'groups'          => $groups,
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

        $model = $this->context->resolve($server)['model'];
        $this->context->authorizeManage($model);

        return $this->service->save($path, $content, $model);
    }

    /**
     * Flattens discovered groups into a plain list of file paths.
     *
     * @param list<array{entries: list<array<string, mixed>>}> $groups
     * @return list<string>
     */
    private function pathsIn(array $groups): array
    {
        $paths = [];

        foreach ($groups as $group) {
            foreach ($group['entries'] as $entry) {
                $paths[] = (string) $entry['path'];
            }
        }

        return $paths;
    }
}
