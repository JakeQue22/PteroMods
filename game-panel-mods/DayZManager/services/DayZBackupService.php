<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Manages DayZ persistence (storage_1) backups for a server.
 *
 * A backup compresses the DayZ persistence folder
 * (`/mpmissions/<mission>/storage_1`) on the game server into a tar.gz
 * archive stored at `/mpmissions/<mission>/storage_1_backups/`.  The backup
 * record in `dayz_backups` carries the mission folder name and the relative
 * archive path so it can be restored at any time.
 *
 * Before any Restore or Restore + Restart a safety backup is taken
 * automatically.
 */
final class DayZBackupService
{
    private const AUTO_BACKUP_INTERVAL_SECONDS = 900;
    private const CACHE_SECONDS = 120;

    /** Sub-folder inside the mission directory where archives are stored. */
    private const BACKUP_SUBDIR = 'storage_1_backups';

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
     * Creates a new backup by compressing the DayZ persistence folder on the
     * game server and recording the archive location in the database.
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

        $missionFolder = $this->resolveMissionFolder($server);

        if ($missionFolder === '') {
            return ['status' => 'error', 'message' => 'Could not find a dayzOffline mission folder under /mpmissions.'];
        }

        $missionRoot = '/mpmissions/' . $missionFolder;

        // Compress storage_1 inside the mission root.
        $archiveName = $this->gateway->compressServerPath($server, $missionRoot, ['storage_1']);

        if ($archiveName === null) {
            return ['status' => 'error', 'message' => 'Wings could not compress the storage_1 folder. Ensure the server container is reachable.'];
        }

        // Move the archive into the dedicated backup sub-directory.
        $timestamp  = date('Ymd_His');
        $safeTrigger = preg_replace('/[^a-z0-9_]/', '_', strtolower($trigger)) ?: 'manual';
        $destName   = 'storage_1_' . $timestamp . '_' . $safeTrigger . '.tar.gz';
        $destRel    = self::BACKUP_SUBDIR . '/' . $destName;

        $this->gateway->renameFile($server, $missionRoot, $archiveName, $destRel);

        $archiveServerPath = $missionRoot . '/' . $destRel;

        try {
            $payload = json_encode([
                'schema_version'  => 2,
                'server_id'       => $serverId,
                'mission_folder'  => $missionFolder,
                'archive_rel_path' => $destRel,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            \Illuminate\Support\Facades\DB::table('dayz_backups')->insert([
                'server_id'  => $serverId,
                'label'      => $label !== '' ? $label : date('Y-m-d H:i:s') . ' backup',
                'trigger'    => $trigger,
                'payload'    => $payload,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => 'Could not save backup record: ' . $exception->getMessage()];
        }

        $this->forgetListCache($serverId);
        $this->pruneOldBackups($serverId, $server, $missionRoot);

        return ['status' => 'created', 'server_id' => $serverId, 'archive' => $archiveServerPath];
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
                ->first(['id', 'server_id', 'payload']);

            if ($row === null) {
                return ['status' => 'error', 'message' => 'Backup not found or does not belong to this server.'];
            }

            $deleted = \Illuminate\Support\Facades\DB::table('dayz_backups')
                ->where('id', $backupId)
                ->where('server_id', $serverId)
                ->delete();

            if ($deleted > 0) {
                // Best-effort: remove the archive from the game server.
                $meta = $this->parseMeta((string) ($row->payload ?? '{}'));

                if (isset($meta['mission_folder'], $meta['archive_rel_path'])) {
                    $missionRoot = '/mpmissions/' . $meta['mission_folder'];
                    $archiveParts = explode('/', $meta['archive_rel_path'], 2);
                    $archiveDir  = count($archiveParts) === 2
                        ? $missionRoot . '/' . $archiveParts[0]
                        : $missionRoot;
                    $archiveFile = count($archiveParts) === 2 ? $archiveParts[1] : $archiveParts[0];

                    try {
                        $this->gateway->deletePath($server, $archiveDir . '/' . $archiveFile);
                    } catch (Throwable) {
                        // Best-effort only; the DB record is already gone.
                    }
                }

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
     * Restores a backup by decompressing its archive on the game server.
     *
     * A safety backup of the current storage_1 is taken automatically before
     * any restore so the previous state can be recovered if needed.
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

        $meta = $this->parseMeta((string) ($row->payload ?? '{}'));

        if (!isset($meta['mission_folder'], $meta['archive_rel_path'])) {
            return ['status' => 'error', 'message' => 'Backup record is missing archive metadata (schema v1 backups cannot be restored this way).'];
        }

        $missionFolder  = (string) $meta['mission_folder'];
        $archiveRelPath = (string) $meta['archive_rel_path'];
        $missionRoot    = '/mpmissions/' . $missionFolder;

        // Take a safety backup before overwriting.
        $this->create($server, 'pre-restore safety backup', 'auto');

        // Remove the current storage_1 so the decompressed archive lands cleanly.
        try {
            $this->gateway->deletePath($server, $missionRoot . '/storage_1');
        } catch (Throwable) {
            // If storage_1 does not exist yet, deletion is a no-op.
        }

        // Decompress the selected backup archive into the mission root.
        $ok = $this->gateway->decompressServerPath($server, $missionRoot, $archiveRelPath);

        if (!$ok) {
            return ['status' => 'error', 'message' => 'Wings could not decompress the backup archive. The previous storage_1 was removed — you may need to restore from another backup.'];
        }

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
     * Resolves the first `dayzOffline.*` subdirectory under `/mpmissions`.
     * Returns an empty string when none is found.
     */
    private function resolveMissionFolder(mixed $server): string
    {
        try {
            $entries = $this->gateway->listDirectory($server, '/mpmissions');

            foreach ($entries as $entry) {
                if (!is_array($entry) || empty($entry['directory'])) {
                    continue;
                }

                $name = trim((string) ($entry['name'] ?? ''));

                if ($name !== '' && str_starts_with(strtolower($name), 'dayzoffline.')) {
                    return $name;
                }
            }
        } catch (Throwable) {
            // Fall through.
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function parseMeta(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
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
                $meta  = $this->parseMeta((string) ($entry['payload'] ?? '{}'));

                unset($entry['payload']);

                $archivePath = '';

                if (isset($meta['mission_folder'], $meta['archive_rel_path'])) {
                    $archivePath = '/mpmissions/' . $meta['mission_folder'] . '/' . $meta['archive_rel_path'];
                }

                return $entry + [
                    'archive_path'   => $archivePath,
                    'schema_version' => (int) ($meta['schema_version'] ?? 1),
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

    private function pruneOldBackups(string $serverId, mixed $server, string $missionRoot): void
    {
        $keep = max(1, (int) $this->settings->get('auto_backup_keep', 10));

        if (!$this->tableExists('dayz_backups')) {
            return;
        }

        try {
            $rows = \Illuminate\Support\Facades\DB::table('dayz_backups')
                ->where('server_id', $serverId)
                ->where('trigger', 'auto')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(['id', 'payload'])
                ->all();

            $toDelete = array_slice($rows, $keep);

            foreach ($toDelete as $row) {
                $rowArray = (array) $row;
                $id       = (int) ($rowArray['id'] ?? 0);

                if ($id <= 0) {
                    continue;
                }

                $meta = $this->parseMeta((string) ($rowArray['payload'] ?? '{}'));

                \Illuminate\Support\Facades\DB::table('dayz_backups')
                    ->where('id', $id)
                    ->where('server_id', $serverId)
                    ->delete();

                if (isset($meta['archive_rel_path'])) {
                    try {
                        $this->gateway->deletePath($server, $missionRoot . '/' . $meta['archive_rel_path']);
                    } catch (Throwable) {
                        // Best-effort.
                    }
                }
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
