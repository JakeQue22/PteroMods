<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZManagerSettingsService;
use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZServerContext;
use Throwable;

/**
 * Manages global DayZ Manager panel settings.
 */
final class DayZManagerSettingsController
{
    public function __construct(
        private readonly DayZManagerSettingsService $settings = new DayZManagerSettingsService(),
        private readonly DayZPageRenderer $renderer = new DayZPageRenderer(),
        private readonly DayZServerContext $context = new DayZServerContext(),
    ) {
    }

    /**
     * Renders the settings page, or returns current settings for API requests.
     *
     * @return mixed
     */
    public function index(mixed $server = null)
    {
        $resolved = $this->context->resolve($server);

        $data = [
            'settings'          => $this->settings->all(),
            'labels'            => DayZManagerSettingsService::LABELS,
            'descriptions'      => DayZManagerSettingsService::DESCRIPTIONS,
            'text_labels'       => DayZManagerSettingsService::TEXT_LABELS,
        ];

        if ($this->context->expectsJson()) {
            return $data;
        }

        return $this->renderer->render('settings', $data, 'settings', $resolved['id'], $resolved['name']);
    }

    /**
     * Saves one or more settings and returns the updated set.
     *
     * @return array<string, mixed>
     */
    public function save(mixed $server = null): array
    {
        $this->context->authorizeManage($this->context->resolve($server)['model']);

        $input = $this->context->input('settings', []);
        $values = is_array($input) ? $input : [];

        // Normalise values: boolean-like strings become bools; plain strings are
        // kept as strings (e.g. the Steam Web API key setting).
        $normalised = [];

        foreach ($values as $key => $value) {
            $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $normalised[(string) $key] = $bool !== null ? $bool : $value;
        }

        $this->settings->saveMany($normalised);

        return [
            'status'   => 'saved',
            'settings' => $this->settings->all(),
        ];
    }
}
