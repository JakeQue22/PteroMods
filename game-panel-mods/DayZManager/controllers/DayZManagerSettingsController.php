<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZManagerSettingsService;
use GamePanelMods\DayZManager\Services\DayZCacheWarmService;
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
        private readonly DayZCacheWarmService $warmer = new DayZCacheWarmService(),
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
        $this->warmer->tick($resolved['model']);

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
            if (in_array((string) $key, [
                'script_log_retention_days',
                'crash_log_retention_days',
                'tm_general_log_retention_days',
                'trader_log_retention_days',
                'dzserver_adm_log_retention_days',
                'dzserver_rpt_log_retention_days',
                'admin_log_retention_days',
                'airdrop_log_retention_days',
                'codelock_log_retention_days',
                'error_log_retention_days',
            ], true)) {
                $normalised[(string) $key] = max(1, min(3650, (int) $value));
                continue;
            }

            if ((string) $key === 'cache_fetch_timer_seconds') {
                $normalised[(string) $key] = max(5, min(3600, (int) $value));
                continue;
            }

            $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $normalised[(string) $key] = $bool !== null ? $bool : $value;
        }

        $this->settings->saveMany($normalised);

        return [
            'status'   => 'saved',
            'settings' => $this->settings->all(),
        ];
    }

    /**
     * Forces DayZ Manager cache refreshes for all supported DayZ data.
     *
     * @return array<string, mixed>
     */
    public function refreshCache(mixed $server = null): array
    {
        $resolved = $this->context->resolve($server);
        $this->context->authorizeManage($resolved['model']);

        try {
            $result = $this->warmer->forceRefreshAll($resolved['model']);

            return ['status' => 'refreshed'] + $result;
        } catch (Throwable $exception) {
            return [
                'status' => 'error',
                'message' => $exception->getMessage(),
            ];
        }
    }
}
