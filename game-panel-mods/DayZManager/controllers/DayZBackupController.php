<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZBackupService;
use GamePanelMods\DayZManager\Services\DayZCacheWarmService;
use GamePanelMods\DayZManager\Services\DayZManagerSettingsService;
use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZServerContext;
use Throwable;

/**
 * Manages database backups for a DayZ server.
 */
final class DayZBackupController
{
    public function __construct(
        private readonly DayZBackupService $service = new DayZBackupService(),
        private readonly DayZCacheWarmService $warmer = new DayZCacheWarmService(),
        private readonly DayZManagerSettingsService $settings = new DayZManagerSettingsService(),
        private readonly DayZPageRenderer $renderer = new DayZPageRenderer(),
        private readonly DayZServerContext $context = new DayZServerContext(),
    ) {
    }

    /**
     * @return mixed
     */
    public function index(mixed $server = null)
    {
        $resolved = $this->context->resolve($server);
        $this->warmer->tick($resolved['model']);

        try {
            $backups = $this->service->list($resolved['model']);
            $allSettings = $this->settings->all();
            $data = [
                'client_id' => $this->context->clientIdentifier($resolved['model'], $resolved['id']),
                'backups'   => $backups,
                'settings'  => [
                    'auto_backup_enabled'          => (bool) ($allSettings['auto_backup_enabled'] ?? false),
                    'auto_backup_interval_minutes' => (int)  ($allSettings['auto_backup_interval_minutes'] ?? 1440),
                    'auto_backup_keep'             => (int)  ($allSettings['auto_backup_keep'] ?? 10),
                ],
            ];
        } catch (Throwable $exception) {
            return $this->renderer->renderError($exception->getMessage(), 'backups', $resolved['id'], $resolved['name']);
        }

        if ($this->context->expectsJson()) {
            return $data;
        }

        return $this->renderer->render('backups', $data, 'backups', $resolved['id'], $resolved['name']);
    }

    /**
     * Creates a new backup.
     *
     * @return array<string, mixed>
     */
    public function create(mixed $server = null): array
    {
        try {
            $model = $this->authorise($server);
            $label = $this->context->stringInput('label');

            return $this->service->create($model, $label, 'manual');
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Restores a backup.
     *
     * @return array<string, mixed>
     */
    public function restore(mixed $server = null, int $backupId = 0): array
    {
        try {
            $model = $this->authorise($server);

            if ($backupId === 0) {
                $backupId = (int) $this->context->stringInput('backup_id');
            }

            $restart = filter_var(
                $this->context->input('restart', false),
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            ) ?? false;

            return $this->service->restore($model, $backupId, $restart);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Deletes a backup.
     *
     * @return array<string, mixed>
     */
    public function delete(mixed $server = null, int $backupId = 0): array
    {
        try {
            $model = $this->authorise($server);

            if ($backupId === 0) {
                $backupId = (int) $this->context->stringInput('backup_id');
            }

            return $this->service->delete($model, $backupId);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Auto-backup tick — called by the page JS to trigger background backups.
     *
     * @return array<string, mixed>
     */
    public function tick(mixed $server = null): array
    {
        $model = $this->authorise($server);

        return $this->service->tick($model);
    }

    /**
     * Saves backup-related settings.
     *
     * @return array<string, mixed>
     */
    public function saveSettings(mixed $server = null): array
    {
        try {
            $this->authorise($server);

            $input = $this->context->input('settings', []);
            $values = is_array($input) ? $input : [];
            $normalised = [];

            foreach (['auto_backup_enabled', 'auto_backup_interval_minutes', 'auto_backup_keep'] as $key) {
                if (!array_key_exists($key, $values)) {
                    continue;
                }

                if ($key === 'auto_backup_enabled') {
                    $normalised[$key] = filter_var($values[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
                } else {
                    $normalised[$key] = max(1, (int) $values[$key]);
                }
            }

            $this->settings->saveMany($normalised);

            return ['status' => 'saved'];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    private function authorise(mixed $server): mixed
    {
        $model = $this->context->resolve($server)['model'];
        $this->context->authorizeManage($model);

        return $model;
    }
}
