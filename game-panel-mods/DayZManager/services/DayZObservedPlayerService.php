<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Persists and serves observed player activity for a DayZ server.
 */
final class DayZObservedPlayerService
{
    public function __construct(
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $players
     */
    public function trackSnapshot(mixed $server, string $mapName, array $players): void
    {
        $serverId = $this->serverId($server);

        if ($serverId === '' || $players === [] || !$this->tableExists()) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        foreach ($players as $player) {
            $playerId = trim((string) ($player['steam64'] ?? $player['player_uid'] ?? ''));

            if ($playerId === '') {
                continue;
            }

            // real_steam64 is set by normalizePlayers() when the identifier is
            // a genuine 17-digit Steam ID, and stored separately from player_id.
            $realSteam64 = isset($player['real_steam64']) && preg_match('/^\d{17}$/', (string) $player['real_steam64']) === 1
                ? (string) $player['real_steam64']
                : null;

            // Strip DayZ-appended duplicate suffixes like "(2)", "(3)" etc.
            $rawName = trim((string) ($player['name'] ?? $playerId));
            $cleanName = trim((string) preg_replace('/\s*\(\d+\)\s*$/', '', $rawName));

            if ($cleanName === '') {
                $cleanName = $rawName;
            }

            // Deduplication: if we already have a row for this steam64 (under a
            // different player_id), treat the existing canonical row as the one to
            // update rather than inserting a second row.
            $canonicalPlayerId = $playerId;

            if ($realSteam64 !== null && $realSteam64 !== $playerId) {
                try {
                    $byS64 = \Illuminate\Support\Facades\DB::table('dayz_observed_players')
                        ->where('server_id', $serverId)
                        ->where('steam64', $realSteam64)
                        ->whereColumn('player_id', '<>', $playerId)
                        ->value('player_id');

                    if ($byS64 !== null && trim((string) $byS64) !== '') {
                        $canonicalPlayerId = trim((string) $byS64);
                    }
                } catch (Throwable) {
                    // best-effort
                }
            }

            try {
                $query = \Illuminate\Support\Facades\DB::table('dayz_observed_players')
                    ->where('server_id', $serverId)
                    ->where('player_id', $canonicalPlayerId);
                $exists = $query->exists();
                $payload = [
                    'player_name'     => $cleanName,
                    'last_map'        => $mapName,
                    'last_x'          => $this->floatValue($player['x'] ?? null),
                    'last_y'          => $this->floatValue($player['y'] ?? null),
                    'last_z'          => $this->floatValue($player['z'] ?? null),
                    'last_direction'  => $this->floatValue($player['direction'] ?? null),
                    'last_alive'      => (bool) ($player['alive'] ?? true),
                    'last_health'     => $this->floatValue($player['health'] ?? null),
                    'inventory_json'  => $this->encodeJson($player['inventory'] ?? null),
                    'metadata_json'   => $this->encodeJson($player['metadata'] ?? null),
                    'last_seen_at'    => $now,
                    'updated_at'      => $now,
                ];

                if ($exists) {
                    $query->update($payload);
                } else {
                    \Illuminate\Support\Facades\DB::table('dayz_observed_players')->insert($payload + [
                        'server_id'     => $serverId,
                        'player_id'     => $canonicalPlayerId,
                        'first_seen_at' => $now,
                        'created_at'    => $now,
                    ]);
                }
            } catch (Throwable) {
                // Best-effort tracking only.
                continue;
            }

            // Store the real Steam64 in its own column (added by a later migration).
            // This is a separate best-effort update so a missing column never
            // blocks the primary player-tracking write above.
            if ($realSteam64 !== null) {
                try {
                    \Illuminate\Support\Facades\DB::table('dayz_observed_players')
                        ->where('server_id', $serverId)
                        ->where('player_id', $canonicalPlayerId)
                        ->update(['steam64' => $realSteam64]);
                } catch (Throwable) {
                    // Column may not exist on older installs without the migration.
                }
            }

            // Track the nickname in the nickname history table (best-effort).
            if ($cleanName !== '' && $cleanName !== $canonicalPlayerId) {
                $this->trackNickname($serverId, $canonicalPlayerId, $cleanName, $now);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $livePlayers
     * @return list<array<string, mixed>>
     */
    public function activity(mixed $server, array $livePlayers = []): array
    {
        $serverId = $this->serverId($server);

        if ($serverId === '') {
            return [];
        }

        $liveIndex = [];

        foreach ($livePlayers as $player) {
            if (!is_array($player)) {
                continue;
            }

            $playerId = trim((string) ($player['steam64'] ?? ''));

            if ($playerId === '') {
                continue;
            }

            $liveIndex[$playerId] = $player;
        }

        $rows = [];

        if ($this->tableExists()) {
            try {
                $rows = \Illuminate\Support\Facades\DB::table('dayz_observed_players')
                    ->where('server_id', $serverId)
                    ->orderByDesc('last_seen_at')
                    ->orderBy('player_name')
                    ->get()
                    ->all();
            } catch (Throwable) {
                $rows = [];
            }
        }

        // Fetch all removed player IDs so they can be filtered out below.
        $removedIds = $this->removedIds($serverId);

        // Load all nickname history rows for this server in one query.
        $allNicknames = $this->allNicknamesByPlayer($serverId);

        $players = [];
        $latestBackups = $this->latestBackups($serverId);

        foreach ($rows as $row) {
            $record = (array) $row;
            $playerId = trim((string) ($record['player_id'] ?? ''));

            if ($playerId === '') {
                continue;
            }

            // Skip permanently removed players.
            if (isset($removedIds[$playerId])) {
                continue;
            }

            $live = $liveIndex[$playerId] ?? [];

            // steam64 column was added in a later migration; fall back to null when absent.
            $dbSteam64 = isset($record['steam64']) && preg_match('/^\d{17}$/', (string) $record['steam64']) === 1
                ? (string) $record['steam64']
                : null;
            // Live snapshot may carry a real_steam64 resolved from persistence.
            $liveSteam64 = isset($live['real_steam64']) && preg_match('/^\d{17}$/', (string) $live['real_steam64']) === 1
                ? (string) $live['real_steam64']
                : null;
            $steam64 = $liveSteam64 ?? $dbSteam64;

            $rawHealth = $this->coalesceFloat($live['health'] ?? null, $record['last_health'] ?? null);
            $health = $rawHealth === null ? null : ($rawHealth > 100 ? round($rawHealth / 100, 2) : $rawHealth);

            // Auto-refresh: when the live snapshot has a name, use it (stripped of any
            // "(2)" suffix) as the current display name.
            $liveName = isset($live['name']) ? trim((string) preg_replace('/\s*\(\d+\)\s*$/', '', (string) $live['name'])) : '';
            $displayName = ($liveName !== '' ? $liveName : null) ?? trim((string) ($record['player_name'] ?? $playerId));

            $players[$playerId] = [
                'player_id'      => $playerId,
                'steam64'        => $steam64,
                'name'           => $displayName,
                'previous_names' => $allNicknames[$playerId] ?? [],
                'map'            => trim((string) (($record['last_map'] ?? '') ?: ($live['map'] ?? ''))),
                'x'              => $this->coalesceFloat($live['x'] ?? null, $record['last_x'] ?? null),
                'y'              => $this->coalesceFloat($live['y'] ?? null, $record['last_y'] ?? null),
                'z'              => $this->coalesceFloat($live['z'] ?? null, $record['last_z'] ?? null),
                'direction'      => $this->coalesceFloat($live['direction'] ?? null, $record['last_direction'] ?? null),
                'alive'          => array_key_exists($playerId, $liveIndex)
                    ? (bool) ($live['alive'] ?? true)
                    : (bool) ($record['last_alive'] ?? false),
                'health'         => $health,
                'online'         => array_key_exists($playerId, $liveIndex),
                'first_seen_at'  => isset($record['first_seen_at']) ? (string) $record['first_seen_at'] : null,
                'last_seen_at'   => isset($record['last_seen_at']) ? (string) $record['last_seen_at'] : null,
                'inventory'      => $this->decodeJson(($live['inventory'] ?? null) !== null ? $live['inventory'] : ($record['inventory_json'] ?? null)),
                'metadata'       => $this->decodeJson(($live['metadata'] ?? null) !== null ? $live['metadata'] : ($record['metadata_json'] ?? null)),
                'reset_backup_id' => $latestBackups[$playerId]['id'] ?? null,
                'reset_backup_at' => $latestBackups[$playerId]['created_at'] ?? null,
            ];
        }

        foreach ($liveIndex as $playerId => $live) {
            if (isset($players[$playerId])) {
                continue;
            }

            // Also skip removed players that happen to be in the live snapshot.
            if (isset($removedIds[$playerId])) {
                continue;
            }

            $liveSteam64 = isset($live['real_steam64']) && preg_match('/^\d{17}$/', (string) $live['real_steam64']) === 1
                ? (string) $live['real_steam64']
                : null;
            $rawHealth = $this->floatValue($live['health'] ?? null);
            $health = $rawHealth === null ? null : ($rawHealth > 100 ? round($rawHealth / 100, 2) : $rawHealth);
            $liveDisplayName = trim((string) preg_replace('/\s*\(\d+\)\s*$/', '', (string) ($live['name'] ?? $playerId)));

            $players[$playerId] = [
                'player_id'      => $playerId,
                'steam64'        => $liveSteam64,
                'name'           => $liveDisplayName !== '' ? $liveDisplayName : $playerId,
                'previous_names' => $allNicknames[$playerId] ?? [],
                'map'            => trim((string) ($live['map'] ?? '')),
                'x'              => $this->floatValue($live['x'] ?? null),
                'y'              => $this->floatValue($live['y'] ?? null),
                'z'              => $this->floatValue($live['z'] ?? null),
                'direction'      => $this->floatValue($live['direction'] ?? null),
                'alive'          => (bool) ($live['alive'] ?? true),
                'health'         => $health,
                'online'         => true,
                'first_seen_at'  => null,
                'last_seen_at'   => null,
                'inventory'      => $this->decodeJson($live['inventory'] ?? null),
                'metadata'       => $this->decodeJson($live['metadata'] ?? null),
                'reset_backup_id' => $latestBackups[$playerId]['id'] ?? null,
                'reset_backup_at' => $latestBackups[$playerId]['created_at'] ?? null,
            ];
        }

        usort($players, static function (array $left, array $right): int {
            $online = ((int) ($right['online'] ?? false)) <=> ((int) ($left['online'] ?? false));

            if ($online !== 0) {
                return $online;
            }

            $rightSeen = strtotime((string) ($right['last_seen_at'] ?? '')) ?: 0;
            $leftSeen = strtotime((string) ($left['last_seen_at'] ?? '')) ?: 0;

            if ($rightSeen !== $leftSeen) {
                return $rightSeen <=> $leftSeen;
            }

            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return array_values($players);
    }

    /**
     * Deletes a player's tracked record from the observed players table, resetting
     * all stored data (name, position, health, inventory, seen timestamps).
     * The record is recreated automatically the next time the player comes online.
     *
     * @return array<string, mixed>
     */
    public function reset(mixed $server, string $playerId): array
    {
        $playerId = trim($playerId);

        if ($playerId === '') {
            return ['status' => 'error', 'message' => 'Player ID is required.'];
        }

        $serverId = $this->serverId($server);

        if ($serverId === '' || !$this->tableExists()) {
            return ['status' => 'error', 'message' => 'Player tracking table is unavailable.'];
        }

        try {
            $query = \Illuminate\Support\Facades\DB::table('dayz_observed_players')
                ->where('server_id', $serverId)
                ->where('player_id', $playerId);
            $record = $query->first();
            $backup = null;

            if ($record !== null && $this->backupTableExists()) {
                $now = date('Y-m-d H:i:s');
                $snapshot = (array) $record;
                unset($snapshot['id']);
                $backupPayload = [
                    'server_id' => $serverId,
                    'player_id' => $playerId,
                    'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $backupId = \Illuminate\Support\Facades\DB::table('dayz_observed_player_backups')
                    ->insertGetId($backupPayload);
                $backup = [
                    'id' => $backupId,
                    'created_at' => $now,
                ];
            }

            $deleted = \Illuminate\Support\Facades\DB::table('dayz_observed_players')
                ->where('server_id', $serverId)
                ->where('player_id', $playerId)
                ->delete();

            return [
                'status'    => 'reset',
                'player_id' => $playerId,
                'backup'    => $backup,
                'message'   => $deleted > 0
                    ? ($backup !== null
                        ? 'Player data has been reset. A backup was saved and can be restored.'
                        : 'Player data has been reset. They will reappear once they next connect.')
                    : 'No tracked record found for that player.',
            ];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(mixed $server, string $playerId, string $backupId = ''): array
    {
        $playerId = trim($playerId);

        if ($playerId === '') {
            return ['status' => 'error', 'message' => 'Player ID is required.'];
        }

        $serverId = $this->serverId($server);

        if ($serverId === '' || !$this->tableExists() || !$this->backupTableExists()) {
            return ['status' => 'error', 'message' => 'Player backup tables are unavailable.'];
        }

        try {
            $query = \Illuminate\Support\Facades\DB::table('dayz_observed_player_backups')
                ->where('server_id', $serverId)
                ->where('player_id', $playerId);

            if (trim($backupId) !== '') {
                $query->where('id', (int) $backupId);
            } else {
                $query->orderByDesc('id');
            }

            $row = $query->first();

            if ($row === null) {
                return ['status' => 'failed', 'message' => 'No backup found for this player.'];
            }

            $backup = (array) $row;
            $snapshot = json_decode((string) ($backup['snapshot_json'] ?? ''), true);

            if (!is_array($snapshot)) {
                return ['status' => 'failed', 'message' => 'Backup data is invalid and cannot be restored.'];
            }

            $now = date('Y-m-d H:i:s');
            $payload = [
                'player_name'    => trim((string) ($snapshot['player_name'] ?? $playerId)),
                'last_map'       => trim((string) ($snapshot['last_map'] ?? '')),
                'last_x'         => $this->floatValue($snapshot['last_x'] ?? null),
                'last_y'         => $this->floatValue($snapshot['last_y'] ?? null),
                'last_z'         => $this->floatValue($snapshot['last_z'] ?? null),
                'last_direction' => $this->floatValue($snapshot['last_direction'] ?? null),
                'last_alive'     => (bool) ($snapshot['last_alive'] ?? false),
                'last_health'    => $this->floatValue($snapshot['last_health'] ?? null),
                'inventory_json' => $this->encodeJson($snapshot['inventory_json'] ?? null),
                'metadata_json'  => $this->encodeJson($snapshot['metadata_json'] ?? null),
                'first_seen_at'  => $snapshot['first_seen_at'] ?? null,
                'last_seen_at'   => $snapshot['last_seen_at'] ?? null,
                'steam64'        => isset($snapshot['steam64']) ? trim((string) $snapshot['steam64']) : null,
                'updated_at'     => $now,
            ];

            \Illuminate\Support\Facades\DB::table('dayz_observed_players')->updateOrInsert(
                ['server_id' => $serverId, 'player_id' => $playerId],
                $payload + ['created_at' => $snapshot['created_at'] ?? $now],
            );

            return [
                'status'    => 'restored',
                'player_id' => $playerId,
                'backup_id' => (int) ($backup['id'] ?? 0),
                'message'   => 'Player data has been restored from backup.',
            ];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function kick(mixed $server, string $playerId): array
    {
        $playerId = trim($playerId);

        if ($playerId === '') {
            return ['status' => 'error', 'message' => 'Player ID is required.'];
        }

        $dispatched = $this->gateway->sendCommand($server, '#kick ' . $playerId);

        return [
            'status'    => $dispatched ? 'dispatched' : 'failed',
            'player_id' => $playerId,
            'message'   => $dispatched
                ? 'Kick command sent to the server console.'
                : 'The server did not accept the kick command.',
        ];
    }

    /**
     * Permanently removes a player from the observed players list and adds them
     * to the removed-players blocklist so they are not re-added on the next
     * live snapshot import.
     *
     * @return array<string, mixed>
     */
    public function removePlayer(mixed $server, string $playerId, string $playerName = '', string $removedBy = ''): array
    {
        $playerId = trim($playerId);

        if ($playerId === '') {
            return ['status' => 'error', 'message' => 'Player ID is required.'];
        }

        $serverId = $this->serverId($server);

        if ($serverId === '') {
            return ['status' => 'error', 'message' => 'Could not resolve server.'];
        }

        try {
            $now = date('Y-m-d H:i:s');

            // Add to the removed-players blocklist (best-effort if table absent).
            if ($this->removedTableExists()) {
                \Illuminate\Support\Facades\DB::table('dayz_removed_players')->updateOrInsert(
                    ['server_id' => $serverId, 'player_id' => $playerId],
                    [
                        'player_name' => $playerName !== '' ? $playerName : $playerId,
                        'removed_by'  => $removedBy,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ],
                );
            }

            // Delete from observed players.
            if ($this->tableExists()) {
                \Illuminate\Support\Facades\DB::table('dayz_observed_players')
                    ->where('server_id', $serverId)
                    ->where('player_id', $playerId)
                    ->delete();
            }

            // Remove nickname history too.
            if ($this->nicknamesTableExists()) {
                \Illuminate\Support\Facades\DB::table('dayz_player_nicknames')
                    ->where('server_id', $serverId)
                    ->where('player_id', $playerId)
                    ->delete();
            }

            return [
                'status'    => 'removed',
                'player_id' => $playerId,
                'message'   => 'Player has been permanently removed from the players list.',
            ];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Returns all known nicknames for a player, ordered most-recently-seen first.
     *
     * @return list<array{nickname:string, last_seen_at:string|null}>
     */
    public function nicknames(string $serverId, string $playerId): array
    {
        if ($serverId === '' || $playerId === '' || !$this->nicknamesTableExists()) {
            return [];
        }

        try {
            return \Illuminate\Support\Facades\DB::table('dayz_player_nicknames')
                ->where('server_id', $serverId)
                ->where('player_id', $playerId)
                ->orderByDesc('last_seen_at')
                ->get()
                ->map(fn ($row) => ['nickname' => (string) ((array) $row)['nickname'], 'last_seen_at' => ((array) $row)['last_seen_at'] ?? null])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Returns a set of all player_id values that have been permanently removed.
     *
     * @return array<string, true>
     */
    public function removedIds(string $serverId): array
    {
        if ($serverId === '' || !$this->removedTableExists()) {
            return [];
        }

        try {
            $ids = \Illuminate\Support\Facades\DB::table('dayz_removed_players')
                ->where('server_id', $serverId)
                ->pluck('player_id')
                ->all();

            return array_fill_keys(array_map('strval', $ids), true);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Loads all nickname rows for a server, grouped by player_id.
     *
     * @return array<string, list<array{nickname:string, last_seen_at:string|null}>>
     */
    private function allNicknamesByPlayer(string $serverId): array
    {
        if ($serverId === '' || !$this->nicknamesTableExists()) {
            return [];
        }

        try {
            $rows = \Illuminate\Support\Facades\DB::table('dayz_player_nicknames')
                ->where('server_id', $serverId)
                ->orderByDesc('last_seen_at')
                ->get()
                ->all();
        } catch (Throwable) {
            return [];
        }

        $byPlayer = [];

        foreach ($rows as $row) {
            $record = (array) $row;
            $pid = trim((string) ($record['player_id'] ?? ''));

            if ($pid === '') {
                continue;
            }

            $byPlayer[$pid][] = [
                'nickname'    => (string) ($record['nickname'] ?? ''),
                'last_seen_at' => isset($record['last_seen_at']) ? (string) $record['last_seen_at'] : null,
            ];
        }

        return $byPlayer;
    }

    private function trackNickname(string $serverId, string $playerId, string $nickname, string $now): void
    {
        if (!$this->nicknamesTableExists()) {
            return;
        }

        try {
            $exists = \Illuminate\Support\Facades\DB::table('dayz_player_nicknames')
                ->where('server_id', $serverId)
                ->where('player_id', $playerId)
                ->where('nickname', $nickname)
                ->exists();

            if ($exists) {
                \Illuminate\Support\Facades\DB::table('dayz_player_nicknames')
                    ->where('server_id', $serverId)
                    ->where('player_id', $playerId)
                    ->where('nickname', $nickname)
                    ->update(['last_seen_at' => $now, 'updated_at' => $now]);
            } else {
                \Illuminate\Support\Facades\DB::table('dayz_player_nicknames')->insert([
                    'server_id'    => $serverId,
                    'player_id'    => $playerId,
                    'nickname'     => $nickname,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            }
        } catch (Throwable) {
            // Best-effort only.
        }
    }

    private function serverId(mixed $server): string
    {
        return $this->context->attribute($server, ['uuid', 'uuidShort', 'id']);
    }

    private function tableExists(): bool
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Schema')) {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\Schema::hasTable('dayz_observed_players');
        } catch (Throwable) {
            return false;
        }
    }

    private function backupTableExists(): bool
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Schema')) {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\Schema::hasTable('dayz_observed_player_backups');
        } catch (Throwable) {
            return false;
        }
    }

    private function nicknamesTableExists(): bool
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Schema')) {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\Schema::hasTable('dayz_player_nicknames');
        } catch (Throwable) {
            return false;
        }
    }

    private function removedTableExists(): bool
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Schema')) {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\Schema::hasTable('dayz_removed_players');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, array{id:int, created_at:string}>
     */
    private function latestBackups(string $serverId): array
    {
        if ($serverId === '' || !$this->backupTableExists()) {
            return [];
        }

        try {
            $latestIds = \Illuminate\Support\Facades\DB::table('dayz_observed_player_backups')
                ->where('server_id', $serverId)
                ->selectRaw('MAX(id) AS id')
                ->groupBy('player_id')
                ->pluck('id')
                ->all();

            if ($latestIds === []) {
                return [];
            }

            $rows = \Illuminate\Support\Facades\DB::table('dayz_observed_player_backups')
                ->whereIn('id', array_map('intval', $latestIds))
                ->get()
                ->all();
        } catch (Throwable) {
            return [];
        }

        $latest = [];

        foreach ($rows as $row) {
            $record = (array) $row;
            $playerId = trim((string) ($record['player_id'] ?? ''));

            if ($playerId === '' || isset($latest[$playerId])) {
                continue;
            }

            $latest[$playerId] = [
                'id' => (int) ($record['id'] ?? 0),
                'created_at' => (string) ($record['created_at'] ?? ''),
            ];
        }

        return $latest;
    }

    private function floatValue(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function coalesceFloat(mixed $preferred, mixed $fallback): ?float
    {
        $value = $this->floatValue($preferred);

        return $value ?? $this->floatValue($fallback);
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return trim($value) === '' ? null : $value;
        }

        try {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return is_string($encoded) ? $encoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
