<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Manages DayZ Manager database backups for a server.
 *
 * A backup is a JSON snapshot of all per-server DB rows (mod order, restart
 * schedule, mod install queue) plus the global player lists and settings.
 * Backups are stored in `dayz_backups` and can be restored at any time;
 * restoring overwrites the live rows and optionally restarts the server so
 * the new mod/configuration state takes effect immediately.
 */
final class DayZBackupService
{
    private const AUTO_BACKUP_INTERVAL_SECONDS = 900;
    private const CACHE_SECONDS = 120;

    /**
     * Tables that carry server-specific rows and are filtered by `server_id`.
     */
    private const SERVER_TABLES = [
        'dayz_server_mod_order',
        'dayz_restart_schedules',
        'dayz_mod_install_queue',
    ];

    /**
     * Tables that are global (no server_id column) and are always included.
     */
    private const GLOBAL_TABLES = [
        'dayz_player_lists',
        'dayz_manager_settings',
    ];

    public function __construct(
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZManagerSettingsService $settings = new DayZManagerSettingsService(),
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZStaleCacheService $staleCache = new DayZStaleCacheService(),
    ) {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Lists all backups for a server, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(mixed $server): array
    {
        $serverId = $this->serverId($server);

        if ($serverId === '' || !$this->tableExists('dayz_backups')) {
            return [];
        }

        /** @var list<array<string, mixed>>|null $rows */
        $rows = $this->staleCache->remember(
            'pteromods.dayz.backups.list.' . md5($serverId),
            self::CACHE_SECONDS,
            self::CACHE_SECONDS * 20,
            fn (): array => $this->loadList($serverId),
            [],
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Creates a new backup and prunes excess backups per the keep setting.
     *
     * @return array<string, mixed>
     */
    public function create(mixed $server, string $label = '', string $trigger = 'manual'): array
    {
        $serverId = $this->serverId($server);

        if ($serverId === '') {
            return ['status' => 'error', 'message' => 'Server could not be resolved.'];
        }

        if (!$this->tableExists('dayz_backups')) {
            return ['status' => 'error', 'message' => 'Backup table not found — run the migrations.'];
        }

        try {
            $payload = $this->buildPayload($serverId);
            $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $backupId = (int) \Illuminate\Support\Facades\DB::table('dayz_backups')->insertGetId([
                'server_id'  => $serverId,
                'label'      => $label !== '' ? $label : date('Y-m-d H:i:s') . ' backup',
                'trigger'    => $trigger,
                'payload'    => $encodedPayload,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => 'Could not save backup: ' . $exception->getMessage()];
        }

        $this->persistBackupFile($serverId, $backupId, $encodedPayload);

        $this->forgetListCache($serverId);
        $this->pruneOldBackups($serverId);

        return ['status' => 'created', 'server_id' => $serverId];
    }

    /**
     * Deletes a backup by id (must belong to the given server).
     *
     * @return array<string, mixed>
     */
    public function delete(mixed $server, int $backupId): array
    {
        $serverId = $this->serverId($server);

        if ($serverId === '' || !$this->tableExists('dayz_backups')) {
            return ['status' => 'error', 'message' => 'Backup not found.'];
        }

        try {
            $row = \Illuminate\Support\Facades\DB::table('dayz_backups')
                ->where('id', $backupId)
                ->where('server_id', $serverId)
                ->first(['id', 'server_id']);

            if ($row === null) {
                return ['status' => 'error', 'message' => 'Backup not found or does not belong to this server.'];
            }

            $deleted = \Illuminate\Support\Facades\DB::table('dayz_backups')
                ->where('id', $backupId)
                ->where('server_id', $serverId)
                ->delete();

            if ($deleted > 0) {
                $this->deleteBackupFile((string) ($row->server_id ?? $serverId), (int) ($row->id ?? $backupId));
                $this->forgetListCache($serverId);
            }

            return $deleted > 0
                ? ['status' => 'deleted']
                : ['status' => 'error', 'message' => 'Backup not found or does not belong to this server.'];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Restores a backup by id and optionally restarts the server.
     *
     * @return array<string, mixed>
     */
    public function restore(mixed $server, int $backupId, bool $restart = false): array
    {
        $serverId = $this->serverId($server);

        if ($serverId === '' || !$this->tableExists('dayz_backups')) {
            return ['status' => 'error', 'message' => 'Backup not found.'];
        }

        try {
            $row = \Illuminate\Support\Facades\DB::table('dayz_backups')
                ->where('id', $backupId)
                ->where('server_id', $serverId)
                ->first(['id', 'payload']);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }

        if ($row === null) {
            return ['status' => 'error', 'message' => 'Backup not found or does not belong to this server.'];
        }

        try {
            $payload = json_decode((string) ($row->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['status' => 'error', 'message' => 'Backup payload is corrupt.'];
        }

        if (!is_array($payload)) {
            return ['status' => 'error', 'message' => 'Backup payload is corrupt.'];
        }

        $this->applyPayload($serverId, $payload);

        if ($restart) {
            $this->gateway->power($server, 'restart');
        }

        return ['status' => 'restored', 'restarted' => $restart];
    }

    /**
     * Auto-backup tick — called on page load.  Only fires when auto-backup is
     * enabled and the configured interval has elapsed since the last run.
     *
     * @return array<string, mixed>
     */
    public function tick(mixed $server): array
    {
        if (!(bool) $this->settings->get('auto_backup_enabled', false)) {
            return ['status' => 'disabled'];
        }

        $serverId = $this->serverId($server);

        if ($serverId === '') {
            return ['status' => 'skipped'];
        }

        $intervalMinutes = max(30, (int) $this->settings->get('auto_backup_interval_minutes', 1440));
        $intervalSeconds = $intervalMinutes * 60;
        $cacheKey = 'pteromods.dayz.backup.last_auto.' . md5($serverId);

        if ($this->recentlyRan($cacheKey, $intervalSeconds)) {
            return ['status' => 'throttled'];
        }

        $result = $this->create($server, '', 'auto');

        if ($result['status'] === 'created') {
            $this->markRan($cacheKey, $intervalSeconds);
        }

        return $result;
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function serverId(mixed $server): string
    {
        return $this->context->attribute($server, ['uuid', 'uuidShort', 'id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(string $serverId): array
    {
        $payload = [
            'schema_version' => 1,
            'server_id'      => $serverId,
            'tables'         => [],
        ];

        foreach (self::SERVER_TABLES as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            try {
                $rows = \Illuminate\Support\Facades\DB::table($table)
                    ->where('server_id', $serverId)
                    ->get()
                    ->all();

                $payload['tables'][$table] = array_map(static fn (mixed $row): array => (array) $row, $rows);
            } catch (Throwable) {
                $payload['tables'][$table] = [];
            }
        }

        foreach (self::GLOBAL_TABLES as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            try {
                $rows = \Illuminate\Support\Facades\DB::table($table)
                    ->get()
                    ->all();

                $payload['tables'][$table] = array_map(static fn (mixed $row): array => (array) $row, $rows);
            } catch (Throwable) {
                $payload['tables'][$table] = [];
            }
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadList(string $serverId): array
    {
        try {
            $rows = \Illuminate\Support\Facades\DB::table('dayz_backups')
                ->where('server_id', $serverId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(['id', 'server_id', 'label', 'trigger', 'created_at', 'payload'])
                ->all();

            return array_map(function (mixed $row): array {
                $entry = (array) $row;
                $file = $this->ensureBackupFile(
                    (string) ($entry['server_id'] ?? ''),
                    (int) ($entry['id'] ?? 0),
                    (string) ($entry['payload'] ?? '')
                );

                unset($entry['payload']);

                return $entry + $file + [
                    'file_size_display' => $this->formatBytes((int) ($file['file_size'] ?? 0)),
                ];
            }, $rows);
        } catch (Throwable) {
            return [];
        }
    }

    private function forgetListCache(string $serverId): void
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return;
        }

        $key = 'pteromods.dayz.backups.list.' . md5($serverId);

        try {
            \Illuminate\Support\Facades\Cache::forget($key);
            \Illuminate\Support\Facades\Cache::forget($key . '.lock');
        } catch (Throwable) {
            // Best-effort cache invalidation only.
        }
    }

    /**
     * @return array{file_path:string, file_size:int}
     */
    private function ensureBackupFile(string $serverId, int $backupId, string $payload): array
    {
        if ($serverId === '' || $backupId <= 0) {
            return ['file_path' => '', 'file_size' => 0];
        }

        $path = $this->backupFilePath($serverId, $backupId);

        if ($payload !== '' && !is_file($path)) {
            $directory = dirname($path);

            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                $this->logBackupFileWarning('Could not create DayZ backup directory.', [
                    'server_id' => $serverId,
                    'backup_id' => $backupId,
                    'directory' => $directory,
                ]);
            } elseif (file_put_contents($path, $payload) === false) {
                $this->logBackupFileWarning('Could not write DayZ backup file.', [
                    'server_id' => $serverId,
                    'backup_id' => $backupId,
                    'path' => $path,
                ]);
            }
        }

        clearstatcache(true, $path);
        $size = is_file($path) ? @filesize($path) : false;

        if ($size === false && $payload === '') {
            return ['file_path' => '', 'file_size' => 0];
        }

        return [
            'file_path' => is_file($path) || $payload !== '' ? $path : '',
            'file_size' => $size === false ? strlen($payload) : (int) $size,
        ];
    }

    private function persistBackupFile(string $serverId, int $backupId, string $payload): void
    {
        $this->ensureBackupFile($serverId, $backupId, $payload);
    }

    private function deleteBackupFile(string $serverId, int $backupId): void
    {
        $path = $this->backupFilePath($serverId, $backupId);

        if (is_file($path) && !unlink($path) && is_file($path)) {
            $this->logBackupFileWarning('Could not delete DayZ backup file.', [
                'server_id' => $serverId,
                'backup_id' => $backupId,
                'path' => $path,
            ]);
        }
    }

    private function backupFilePath(string $serverId, int $backupId): string
    {
        return rtrim($this->backupRootPath(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $this->safePathSegment($serverId)
            . DIRECTORY_SEPARATOR
            . 'backup_' . $backupId . '.json';
    }

    private function backupRootPath(): string
    {
        if (function_exists('storage_path')) {
            return storage_path('app/pteromods/dayz-backups');
        }

        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pteromods-dayz-backups';
    }

    private function safePathSegment(string $value): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $value) ?? '';
        $clean = trim($clean, '_-');

        return $clean !== '' ? $clean : 'server';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $size = $bytes / 1024;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return number_format($size, $size >= 10 ? 1 : 2) . ' ' . $units[$unitIndex];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logBackupFileWarning(string $message, array $context): void
    {
        try {
            if (class_exists('Illuminate\\Support\\Facades\\Log')) {
                \Illuminate\Support\Facades\Log::warning($message, $context);

                return;
            }
        } catch (Throwable) {
            // Fall back to PHP's error log below.
        }

        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log($message . ' ' . ($encoded !== false ? $encoded : ''));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyPayload(string $serverId, array $payload): void
    {
        $tables = is_array($payload['tables'] ?? null) ? $payload['tables'] : [];

        // Only per-server tables are restored automatically.  Global tables
        // (dayz_player_lists, dayz_manager_settings) are included in the backup
        // payload for reference but are intentionally not restored here: wiping
        // those tables would affect every other server sharing the database.
        foreach (self::SERVER_TABLES as $table) {
            if (!isset($tables[$table]) || !is_array($tables[$table])) {
                continue;
            }

            if (!$this->tableExists($table)) {
                continue;
            }

            try {
                \Illuminate\Support\Facades\DB::table($table)
                    ->where('server_id', $serverId)
                    ->delete();

                foreach ($tables[$table] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $row['server_id'] = $serverId;
                    unset($row['id']);

                    \Illuminate\Support\Facades\DB::table($table)->insert($row);
                }
            } catch (Throwable) {
                // Best-effort: continue restoring other tables.
            }
        }
    }

    private function pruneOldBackups(string $serverId): void
    {
        $keep = max(1, (int) $this->settings->get('auto_backup_keep', 10));

        if (!$this->tableExists('dayz_backups')) {
            return;
        }

        try {
            // Only auto-generated backups are subject to the retention limit;
            // manual backups are kept until the user explicitly deletes them.
            $ids = \Illuminate\Support\Facades\DB::table('dayz_backups')
                ->where('server_id', $serverId)
                ->where('trigger', 'auto')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->pluck('id')
                ->all();

            $toDelete = array_slice($ids, $keep);

            if ($toDelete !== []) {
                \Illuminate\Support\Facades\DB::table('dayz_backups')
                    ->whereIn('id', $toDelete)
                    ->delete();
            }
        } catch (Throwable) {
            // Best-effort pruning only.
        }
    }

    private function recentlyRan(string $cacheKey, int $intervalSeconds): bool
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return false;
        }

        try {
            $last = \Illuminate\Support\Facades\Cache::get($cacheKey);

            return is_numeric($last) && ((int) $last) > (time() - $intervalSeconds);
        } catch (Throwable) {
            return false;
        }
    }

    private function markRan(string $cacheKey, int $intervalSeconds): void
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Cache::put($cacheKey, time(), $intervalSeconds * 2);
        } catch (Throwable) {
            // Best-effort cache write only.
        }
    }

    private function tableExists(string $table): bool
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Schema')) {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
