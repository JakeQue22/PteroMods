<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

/**
 * Supplies transient live-map player snapshots from server-side bridge files.
 */
final class DayZLiveMapService
{
    private const SNAPSHOT_CACHE_SECONDS = 30;
    private const BRIDGE_FILES = [
        '/profiles/PteroMods/live_map_players.json',
        '/profiles/live_map_players.json',
    ];

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZServerQueryService $query = new DayZServerQueryService(),
        private readonly DayZManagerSettingsService $settings = new DayZManagerSettingsService(),
        private readonly DayZObservedPlayerService $observedPlayers = new DayZObservedPlayerService(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(mixed $server): array
    {
        $cached = $this->cachedSnapshot($server);

        if (is_array($cached)) {
            $data = $cached;
            $source = 'cache';
        } else {
            $raw = null;
            $source = '';

            foreach (self::BRIDGE_FILES as $path) {
                $raw = $this->gateway->readFile($server, $path);

                if (is_string($raw) && trim($raw) !== '') {
                    $source = $path;
                    break;
                }
            }

            $data = $this->decodeSnapshot($raw, $server);
        }
        $query = $this->query->query($server);
        $mapName = $this->resolveMapName($data, $query);
        $players = $this->normalizePlayers(is_array($data) ? ($data['players'] ?? []) : []);
        $this->observedPlayers->trackSnapshot($server, $mapName, $players);
        $updatedAt = $this->normalizeUpdatedAt(is_array($data) ? ($data['updated_at'] ?? '') : '');

        if ($source === '') {
            $status = 'waiting_for_bridge';
        } elseif (!is_array($data)) {
            $status = 'invalid_bridge_payload';
        } elseif (trim((string) ($data['mapName'] ?? $data['map'] ?? '')) === '' && ($data['players'] ?? []) === []) {
            // The snapshot file exists but map is empty and players is empty — this is
            // the initial placeholder written by the panel deploy step.  The game bridge
            // has not written a real snapshot yet (likely because the PteroMods output
            // directory did not exist when the bridge first ran).
            $status = 'stale_snapshot';
        } else {
            $status = 'ok';
        }

        return [
            'status' => $status,
            'source' => $source,
            'map' => $mapName,
            'map_definition' => $this->mapDefinition($mapName),
            'players' => $players,
            'online_count' => count($players),
            'last_update' => $updatedAt,
            'query_online' => (bool) ($query['online'] ?? false),
            'query_player_count' => (int) (($query['players'] ?? 0) ?: 0),
            'bridge_format' => [
                'path' => self::BRIDGE_FILES[0],
                'ingest_endpoint' => '/api/server/{server}/dayz/live-map/ingest',
                'signature_header' => 'X-DayZ-Bridge-Signature',
                'schema' => [
                    'updated_at' => '2026-08-11T11:20:14Z',
                    'map' => 'ChernarusPlus',
                    'players' => [[
                        'steam64' => '76561198000000000',
                        'player_uid' => '76561198000000000',
                        'real_steam64' => '76561198000000000',
                        'name' => 'PlayerName',
                        'x' => 7500.0,
                        'y' => 50.0,
                        'z' => 7200.0,
                        'direction' => 180.0,
                        'alive' => true,
                        'health' => 92.5,
                    ]],
                ],
            ],
        ];
    }

    /**
     * @param mixed $raw
     * @return list<array<string, mixed>>
     */
    private function normalizePlayers(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $players = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $steam64 = trim((string) ($entry['steam64'] ?? $entry['steam_id'] ?? ''));
            $playerUid = trim((string) ($entry['player_uid'] ?? ''));
            $name = trim((string) ($entry['name'] ?? $entry['player_name'] ?? ''));
            $x = $this->floatValue($entry['x'] ?? ($entry['position']['x'] ?? null));
            $y = $this->floatValue($entry['y'] ?? ($entry['position']['y'] ?? null));
            $z = $this->floatValue($entry['z'] ?? ($entry['position']['z'] ?? null));

            // Primary stable identifier: prefer player_uid (DayZ UID), fall back to
            // steam64 (older bridge deployments store the DayZ UID in that field).
            $primaryId = $playerUid !== '' ? $playerUid : $steam64;

            if ($primaryId === '' || $name === '' || $x === null || $z === null) {
                continue;
            }

            // Health comes from the bridge on a 0–100 scale. Older bridge
            // deployments mistakenly multiplied by 100 (yielding 0–10 000);
            // normalise those values back to 0–100 here.
            $rawHealth = $this->floatValue($entry['health'] ?? null);
            $health = $rawHealth === null ? null : ($rawHealth > 100 ? round($rawHealth / 100, 2) : $rawHealth);

            // Accept both raw 17-digit Steam64 values and wrappers such as
            // "steam:7656119..." by extracting the 17-digit token.
            $realSteam64 = $this->extractSteam64($steam64);

            $players[] = [
                'steam64' => $primaryId,   // used as map marker key (may be DayZ UID)
                'real_steam64' => $realSteam64,  // actual Steam64 if available, else null
                'steam64_raw' => $steam64 !== '' ? $steam64 : null,
                'player_uid' => $playerUid !== ''
                    ? $playerUid
                    : ($realSteam64 === null ? $primaryId : null), // old bridge fallback where steam64 carried UID
                'name' => $name,
                'x' => $x,
                'y' => $y ?? 0.0,
                'z' => $z,
                'direction' => $this->floatValue($entry['direction'] ?? $entry['yaw'] ?? 0.0) ?? 0.0,
                'alive' => (bool) ($entry['alive'] ?? true),
                'health' => $health,
                'inventory' => $this->normalizeOptionalData($entry['inventory'] ?? null),
                'metadata' => $this->normalizeMetadata($entry),
            ];
        }

        return $players;
    }

    private function extractSteam64(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{17}$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/(\d{17})/', $value, $matches) === 1) {
            return (string) ($matches[1] ?? '');
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $query
     */
    private function resolveMapName(?array $data, array $query): string
    {
        $map = trim((string) (($data['mapName'] ?? $data['map'] ?? '') ?: ($query['map'] ?? '')));

        if ($map === '') {
            return 'ChernarusPlus';
        }

        return match (strtolower($map)) {
            'dayzoffline.chernarusplus', 'chernarusplus', 'chernarus' => 'ChernarusPlus',
            'dayzoffline.enoch', 'enoch', 'livonia' => 'Livonia',
            'dayzoffline.sakhal', 'sakhal' => 'Sakhal',
            default => $map,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDefinition(string $map): array
    {
        $definitions = [
            'ChernarusPlus' => [
                'id' => 'chernarusplus',
                'name' => 'ChernarusPlus',
                'world_size' => 15360.0,
                'locations' => [
                    ['name' => 'Balota', 'x' => 4460.0, 'z' => 2450.0],
                    ['name' => 'Chernogorsk', 'x' => 6620.0, 'z' => 2650.0],
                    ['name' => 'Elektrozavodsk', 'x' => 10460.0, 'z' => 2280.0],
                    ['name' => 'Stary Sobor', 'x' => 6320.0, 'z' => 7800.0],
                    ['name' => 'Vybor', 'x' => 3830.0, 'z' => 8910.0],
                    ['name' => 'Gorka', 'x' => 9600.0, 'z' => 8850.0],
                    ['name' => 'Berezino', 'x' => 12050.0, 'z' => 9150.0],
                    ['name' => 'Severograd', 'x' => 8090.0, 'z' => 12700.0],
                    ['name' => 'Novodmitrovsk', 'x' => 11350.0, 'z' => 14450.0],
                    ['name' => 'Tisy', 'x' => 1650.0, 'z' => 14350.0],
                ],
            ],
            'Livonia' => [
                'id' => 'livonia',
                'name' => 'Livonia',
                'world_size' => 12800.0,
                'locations' => [
                    ['name' => 'Brenna', 'x' => 5600.0, 'z' => 5700.0],
                    ['name' => 'Nadbór', 'x' => 7850.0, 'z' => 5950.0],
                    ['name' => 'Topolin', 'x' => 9800.0, 'z' => 10250.0],
                    ['name' => 'Sitnik', 'x' => 6650.0, 'z' => 9450.0],
                    ['name' => 'Swarog', 'x' => 3500.0, 'z' => 10100.0],
                ],
            ],
            'Sakhal' => [
                'id' => 'sakhal',
                'name' => 'Sakhal',
                'world_size' => 12800.0,
                'locations' => [
                    ['name' => 'Ayan', 'x' => 2875.0, 'z' => 2650.0],
                    ['name' => 'Yasny', 'x' => 7200.0, 'z' => 3150.0],
                    ['name' => 'Boreal Ridge', 'x' => 4200.0, 'z' => 7800.0],
                    ['name' => 'Tungar', 'x' => 9850.0, 'z' => 8900.0],
                    ['name' => 'Nizhnoye', 'x' => 6400.0, 'z' => 11200.0],
                ],
            ],
        ];

        $base = $definitions[$map] ?? ['id' => strtolower($map), 'name' => $map, 'world_size' => 15360.0, 'locations' => []];

        return $base + [
            'origin' => ['x' => 0.0, 'z' => 0.0],
            'axis' => ['x' => 'east', 'z' => 'south'],
        ];
    }

    private function normalizeUpdatedAt(mixed $value): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return gmdate('c');
        }

        $timestamp = strtotime($text);

        return $timestamp === false ? gmdate('c') : gmdate('c', $timestamp);
    }

    private function floatValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function normalizeOptionalData(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function normalizeMetadata(array $entry): ?array
    {
        $metadata = $entry['metadata'] ?? $entry['meta'] ?? null;

        if (is_array($metadata)) {
            return $metadata;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function ingest(mixed $server, array $payload, string $signature): array
    {
        $secret = trim((string) $this->settings->get('live_map_bridge_secret', ''));

        if ($secret === '') {
            return ['status' => 'failed', 'message' => 'Live map bridge secret is not configured.'];
        }

        $serverId = (new DayZServerContext())->attribute($server, ['uuid', 'uuidShort', 'id']);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($serverId === '' || !is_string($json)) {
            return ['status' => 'failed', 'message' => 'Invalid ingest payload.'];
        }

        $expected = hash_hmac('sha256', $serverId . ':' . $json, $secret);

        if (!hash_equals($expected, strtolower(trim($signature)))) {
            return ['status' => 'failed', 'message' => 'Invalid bridge signature.'];
        }

        if (!is_array($payload['players'] ?? null)) {
            return ['status' => 'failed', 'message' => 'Invalid payload: players must be an array.'];
        }

        $cacheKey = $this->snapshotCacheKey($serverId);

        if (class_exists('Illuminate\\Support\\Facades\\Cache')) {
            try {
                \Illuminate\Support\Facades\Cache::put($cacheKey, $payload, self::SNAPSHOT_CACHE_SECONDS);
            } catch (\Throwable) {
                // Ignore and fall through.
            }
        }

        return ['status' => 'accepted', 'players' => count($payload['players'])];
    }

    /**
     * Accepts either a direct payload or a signed envelope:
     * {"payload": {...}, "signature": "hex-hmac-sha256"}.
     *
     * @return array<string, mixed>|null
     */
    private function decodeSnapshot(?string $raw, mixed $server): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        $secret = trim((string) $this->settings->get('live_map_bridge_secret', ''));

        if (!isset($decoded['payload']) || !is_array($decoded['payload'])) {
            return $decoded;
        }

        if ($secret === '') {
            return $decoded['payload'];
        }

        $signature = trim((string) ($decoded['signature'] ?? ''));

        if ($signature === '') {
            return null;
        }

        $serverId = (new DayZServerContext())->attribute($server, ['uuid', 'uuidShort', 'id']);
        $base = json_encode($decoded['payload'], JSON_UNESCAPED_SLASHES) ?: '';
        $expected = hash_hmac('sha256', $serverId . ':' . $base, $secret);

        return hash_equals($expected, strtolower($signature)) ? $decoded['payload'] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cachedSnapshot(mixed $server): ?array
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return null;
        }

        $serverId = (new DayZServerContext())->attribute($server, ['uuid', 'uuidShort', 'id']);

        if ($serverId === '') {
            return null;
        }

        try {
            $cached = \Illuminate\Support\Facades\Cache::get($this->snapshotCacheKey($serverId));
            return is_array($cached) ? $cached : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function snapshotCacheKey(string $serverId): string
    {
        return 'pteromods.dayz.live_map.snapshot.' . md5($serverId);
    }
}
