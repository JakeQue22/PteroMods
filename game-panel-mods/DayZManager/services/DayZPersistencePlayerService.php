<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Reads persisted DayZ player records from characters.db / players.db.
 *
 * The database is read through the first available {@see DayZSqliteSource}:
 * the native `sqlite3` extension, PDO's `sqlite` driver, or the bundled
 * dependency-free {@see DayZSqliteFileReader}. The last one guarantees the
 * feature works on panel images whose PHP runtime ships no SQLite support.
 */
final class DayZPersistencePlayerService
{
    private const TABLE_ROW_LIMIT = 3000;

    private const FALLBACK_DB_PATHS = [
        '/storage_1/characters.db',
        '/storage_1/players.db',
        '/storage_1/data/characters.db',
        '/storage_1/data/players.db',
    ];

    private const COLUMN_PATTERNS = [
        'uid' => '/^(uid|player_?uid|steam64|steam_?id|bohemia_?id|identity_?id|player_?id|owner_?id)$/i',
        'name' => '/(name|nickname|playername)/i',
        'x' => '/(^x$|posx|positionx|worldx|coordx)/i',
        'y' => '/(^y$|posy|positiony|worldy|coordy|height)/i',
        'z' => '/(^z$|posz|positionz|worldz|coordz)/i',
        'vector' => '/(^pos$|position|vector|location)/i',
        'seen' => '/(last.*(seen|login|played)|updated|created|timestamp|time)/i',
        'alive' => '/(alive|is_alive|isdead|dead)/i',
    ];

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
    ) {
    }

    /**
     * @return array{status: string, source_path: string|null, players: list<array<string, mixed>>}
     */
    public function snapshot(mixed $server, string $mapName = 'ChernarusPlus'): array
    {
        $readablePath = null;

        foreach ($this->candidatePaths($server) as $path) {
            $raw = $this->gateway->readFile($server, $path);

            if (!is_string($raw) || !str_starts_with($raw, "SQLite format 3\0")) {
                continue;
            }

            $players = $this->extractPlayers($raw, $mapName);

            if (!is_array($players)) {
                continue;
            }

            if ($players !== []) {
                return ['status' => 'ok', 'source_path' => $path, 'players' => $players];
            }

            // A readable database without player rows (for example an empty
            // storage folder) is remembered, but the remaining candidate paths
            // are still checked for one that holds records.
            $readablePath ??= $path;
        }

        return $readablePath === null
            ? ['status' => 'not_found', 'source_path' => null, 'players' => []]
            : ['status' => 'ok', 'source_path' => $readablePath, 'players' => []];
    }

    /**
     * @return list<string>
     */
    private function candidatePaths(mixed $server): array
    {
        $paths = self::FALLBACK_DB_PATHS;

        try {
            $entries = $this->gateway->listDirectory($server, '/mpmissions');

            foreach ($entries as $entry) {
                if (!is_array($entry) || empty($entry['directory'])) {
                    continue;
                }

                $name = trim((string) ($entry['name'] ?? ''));

                if ($name === '' || !str_starts_with(strtolower($name), 'dayzoffline.')) {
                    continue;
                }

                $paths[] = '/mpmissions/' . $name . '/storage_1/characters.db';
                $paths[] = '/mpmissions/' . $name . '/storage_1/players.db';
                $paths[] = '/mpmissions/' . $name . '/storage_1/data/characters.db';
                $paths[] = '/mpmissions/' . $name . '/storage_1/data/players.db';
            }
        } catch (Throwable) {
            // Fall back to defaults.
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function extractPlayers(string $raw, string $mapName): ?array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'pteromods-dayz-db-');

        if ($tmpPath === false) {
            return null;
        }

        @chmod($tmpPath, 0600);

        if (file_put_contents($tmpPath, $raw) === false) {
            @unlink($tmpPath);

            return null;
        }

        $source = $this->openSource($tmpPath);

        if ($source === null) {
            @unlink($tmpPath);

            return null;
        }

        try {
            return $this->readPlayers($source, $mapName);
        } catch (Throwable) {
            return null;
        } finally {
            $source->close();
            @unlink($tmpPath);
        }
    }

    /**
     * Opens the database with the first SQLite implementation available in the
     * current PHP runtime.
     */
    private function openSource(string $path): ?DayZSqliteSource
    {
        return DayZSqliteExtensionSource::open($path)
            ?? DayZSqlitePdoSource::open($path)
            ?? DayZSqliteFileReader::open($path);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readPlayers(DayZSqliteSource $source, string $mapName): array
    {
        $merged = [];

        foreach ($source->tables() as $table) {
            foreach ($this->extractFromTable($source, $table, $mapName) as $row) {
                $uid = (string) ($row['player_id'] ?? '');

                if ($uid === '') {
                    continue;
                }

                $merged[$uid] = isset($merged[$uid]) ? $this->mergeRow($merged[$uid], $row) : $row;
            }
        }

        return $this->sortMerged($merged);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractFromTable(DayZSqliteSource $source, string $table, string $mapName): array
    {
        $columns = $source->columns($table);

        if ($columns === []) {
            return [];
        }

        $mapping = [];

        foreach (self::COLUMN_PATTERNS as $field => $pattern) {
            $column = $this->firstColumn($columns, $pattern);

            if ($column !== null) {
                $mapping[$field] = $column;
            }
        }

        if (!isset($mapping['uid'])) {
            return [];
        }

        $rows = $source->rows($table, array_values(array_unique($mapping)), self::TABLE_ROW_LIMIT);
        $players = [];

        foreach ($rows as $row) {
            $player = $this->mapRow($row, $mapping, $table, $mapName);

            if ($player !== null) {
                $players[] = $player;
            }
        }

        return $players;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $mapping
     * @return array<string, mixed>|null
     */
    private function mapRow(array $row, array $mapping, string $table, string $mapName): ?array
    {
        $value = static fn (string $field): mixed => isset($mapping[$field]) ? ($row[$mapping[$field]] ?? null) : null;
        $playerId = trim((string) ($this->scalar($value('uid')) ?? ''));

        if ($playerId === '') {
            return null;
        }

        $vector = $this->parseVector($this->scalar($value('vector')));
        $x = $this->floatValue($value('x')) ?? ($vector[0] ?? null);
        $y = $this->floatValue($value('y')) ?? ($vector[1] ?? null);
        $z = $this->floatValue($value('z')) ?? ($vector[2] ?? null);
        $name = trim((string) ($this->scalar($value('name')) ?? ''));
        $status = $this->positionStatus($x, $z, $mapName);

        return [
            'player_id' => $playerId,
            'steam64' => preg_match('/^\d{17}$/', $playerId) === 1 ? $playerId : null,
            'name' => $name !== '' ? $name : $playerId,
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'position_status' => $status,
            'position_valid' => $status === 'valid',
            'alive' => $this->aliveValue($value('alive')),
            'last_seen_at' => $this->normalizeTimestamp($value('seen')),
            'source_table' => $table,
        ];
    }

    /**
     * Binary blob columns are never usable as identifiers, names, or vectors.
     */
    private function scalar(mixed $value): string|int|float|null
    {
        if ($value === null || is_int($value) || is_float($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        return preg_match('//u', $value) === 1 && !str_contains($value, "\0") ? $value : null;
    }

    /**
     * @param array<string, array<string, mixed>> $merged
     * @return list<array<string, mixed>>
     */
    private function sortMerged(array $merged): array
    {
        usort($merged, static function (array $left, array $right): int {
            $rightSeen = strtotime((string) ($right['last_seen_at'] ?? '')) ?: 0;
            $leftSeen = strtotime((string) ($left['last_seen_at'] ?? '')) ?: 0;

            if ($rightSeen !== $leftSeen) {
                return $rightSeen <=> $leftSeen;
            }

            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return array_values($merged);
    }

    /**
     * @param list<string> $columns
     */
    private function firstColumn(array $columns, string $pattern): ?string
    {
        foreach ($columns as $column) {
            if (preg_match($pattern, $column) === 1) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null
     */
    private function parseVector(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        preg_match_all('/-?\d+(?:\.\d+)?/', $value, $matches);

        if (count($matches[0] ?? []) < 3) {
            return null;
        }

        return [
            (float) $matches[0][0],
            (float) $matches[0][1],
            (float) $matches[0][2],
        ];
    }

    private function floatValue(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function aliveValue(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) > 0;
        }

        if (!is_string($value)) {
            return null;
        }

        $text = strtolower(trim($value));

        if (in_array($text, ['true', 'alive', 'yes'], true)) {
            return true;
        }

        if (in_array($text, ['false', 'dead', 'no'], true)) {
            return false;
        }

        return null;
    }

    private function normalizeTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $int = (int) $value;

            if ($int > 0) {
                return gmdate('Y-m-d H:i:s', $int);
            }
        }

        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        $time = strtotime($text);

        return $time === false ? $text : gmdate('Y-m-d H:i:s', $time);
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private function mergeRow(array $left, array $right): array
    {
        foreach (['name', 'x', 'y', 'z', 'alive', 'last_seen_at'] as $field) {
            if (($left[$field] ?? null) === null || ($left[$field] ?? '') === '') {
                $left[$field] = $right[$field] ?? null;
            }
        }

        if (($left['position_status'] ?? 'missing') !== 'valid' && ($right['position_status'] ?? 'missing') === 'valid') {
            $left['x'] = $right['x'] ?? $left['x'] ?? null;
            $left['y'] = $right['y'] ?? $left['y'] ?? null;
            $left['z'] = $right['z'] ?? $left['z'] ?? null;
            $left['position_status'] = 'valid';
            $left['position_valid'] = true;
        }

        return $left;
    }

    private function positionStatus(?float $x, ?float $z, string $mapName): string
    {
        if ($x === null || $z === null) {
            return 'missing';
        }

        $size = match (strtolower($mapName)) {
            'livonia', 'dayzoffline.enoch', 'enoch', 'sakhal', 'dayzoffline.sakhal' => 12800.0,
            default => 15360.0,
        };

        if (!is_finite($x) || !is_finite($z)) {
            return 'out_of_bounds';
        }

        return $x >= 0.0 && $z >= 0.0 && $x <= $size && $z <= $size
            ? 'valid'
            : 'out_of_bounds';
    }
}
