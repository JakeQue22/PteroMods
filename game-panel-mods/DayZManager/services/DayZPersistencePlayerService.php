<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use SQLite3;
use Throwable;

/**
 * Reads persisted DayZ player records from characters.db / players.db.
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

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
    ) {
    }

    /**
     * @return array{status: string, source_path: string|null, players: list<array<string, mixed>>}
     */
    public function snapshot(mixed $server, string $mapName = 'ChernarusPlus'): array
    {
        if (!class_exists(SQLite3::class)) {
            return ['status' => 'sqlite_extension_missing', 'source_path' => null, 'players' => []];
        }

        foreach ($this->candidatePaths($server) as $path) {
            $raw = $this->gateway->readFile($server, $path);

            if (!is_string($raw) || $raw === '') {
                continue;
            }

            $players = $this->extractPlayers($raw, $mapName);

            if (is_array($players)) {
                return ['status' => 'ok', 'source_path' => $path, 'players' => $players];
            }
        }

        return ['status' => 'not_found', 'source_path' => null, 'players' => []];
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

        if (!str_starts_with($raw, "SQLite format 3\000")) {
            @unlink($tmpPath);
            return null;
        }

        @chmod($tmpPath, 0600);
        file_put_contents($tmpPath, $raw);

        try {
            $db = new SQLite3($tmpPath, SQLITE3_OPEN_READONLY);
        } catch (Throwable) {
            @unlink($tmpPath);
            return null;
        }

        try {
            $merged = [];

            foreach ($this->tables($db) as $table) {
                $rows = $this->extractFromTable($db, $table, $mapName);

                foreach ($rows as $row) {
                    $uid = (string) ($row['player_id'] ?? '');

                    if ($uid === '') {
                        continue;
                    }

                    if (!isset($merged[$uid])) {
                        $merged[$uid] = $row;
                        continue;
                    }

                    $merged[$uid] = $this->mergeRow($merged[$uid], $row);
                }
            }

            usort($merged, static function (array $left, array $right): int {
                $rightSeen = strtotime((string) ($right['last_seen_at'] ?? '')) ?: 0;
                $leftSeen = strtotime((string) ($left['last_seen_at'] ?? '')) ?: 0;

                if ($rightSeen !== $leftSeen) {
                    return $rightSeen <=> $leftSeen;
                }

                return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            });

            return array_values($merged);
        } finally {
            $db->close();
            @unlink($tmpPath);
        }
    }

    /**
     * @return list<string>
     */
    private function tables(SQLite3 $db): array
    {
        $query = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");

        if (!$query) {
            return [];
        }

        $tables = [];

        while (($row = $query->fetchArray(SQLITE3_ASSOC)) !== false) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name !== '') {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractFromTable(SQLite3 $db, string $table, string $mapName): array
    {
        $columns = $this->columns($db, $table);

        if ($columns === []) {
            return [];
        }

        $uidColumn = $this->firstColumn($columns, '/^(uid|player_?uid|steam64|steam_?id|bohemia_?id|identity_?id|player_?id|owner_?id)$/i');
        $nameColumn = $this->firstColumn($columns, '/(name|nickname|playername)/i');
        $xColumn = $this->firstColumn($columns, '/(^x$|posx|positionx|worldx|coordx)/i');
        $yColumn = $this->firstColumn($columns, '/(^y$|posy|positiony|worldy|coordy|height)/i');
        $zColumn = $this->firstColumn($columns, '/(^z$|posz|positionz|worldz|coordz)/i');
        $vectorColumn = $this->firstColumn($columns, '/(^pos$|position|vector|location)/i');
        $seenColumn = $this->firstColumn($columns, '/(last.*(seen|login|played)|updated|created|timestamp|time)/i');
        $aliveColumn = $this->firstColumn($columns, '/(alive|is_alive|isdead|dead)/i');

        if ($uidColumn === null) {
            return [];
        }

        $select = [
            '"' . str_replace('"', '""', $uidColumn) . '" AS __uid',
        ];

        if ($nameColumn !== null) {
            $select[] = '"' . str_replace('"', '""', $nameColumn) . '" AS __name';
        }

        if ($xColumn !== null) {
            $select[] = '"' . str_replace('"', '""', $xColumn) . '" AS __x';
        }

        if ($yColumn !== null) {
            $select[] = '"' . str_replace('"', '""', $yColumn) . '" AS __y';
        }

        if ($zColumn !== null) {
            $select[] = '"' . str_replace('"', '""', $zColumn) . '" AS __z';
        }

        if ($vectorColumn !== null) {
            $select[] = '"' . str_replace('"', '""', $vectorColumn) . '" AS __vector';
        }

        if ($seenColumn !== null) {
            $select[] = '"' . str_replace('"', '""', $seenColumn) . '" AS __seen';
        }

        if ($aliveColumn !== null) {
            $select[] = '"' . str_replace('"', '""', $aliveColumn) . '" AS __alive';
        }

        $query = $db->query(sprintf(
            'SELECT %s FROM "%s" LIMIT %d',
            implode(', ', $select),
            str_replace('"', '""', $table),
            self::TABLE_ROW_LIMIT,
        ));

        if (!$query) {
            return [];
        }

        $players = [];

        while (($row = $query->fetchArray(SQLITE3_ASSOC)) !== false) {
            $playerId = trim((string) ($row['__uid'] ?? ''));

            if ($playerId === '') {
                continue;
            }

            $steam64 = preg_match('/^\d{17}$/', $playerId) === 1 ? $playerId : null;
            $vector = $this->parseVector($row['__vector'] ?? null);
            $x = $this->floatValue($row['__x'] ?? null) ?? ($vector[0] ?? null);
            $y = $this->floatValue($row['__y'] ?? null) ?? ($vector[1] ?? null);
            $z = $this->floatValue($row['__z'] ?? null) ?? ($vector[2] ?? null);
            $name = trim((string) ($row['__name'] ?? ''));
            $status = $this->positionStatus($x, $z, $mapName);

            $players[] = [
                'player_id' => $playerId,
                'steam64' => $steam64,
                'name' => $name !== '' ? $name : $playerId,
                'x' => $x,
                'y' => $y,
                'z' => $z,
                'position_status' => $status,
                'position_valid' => $status === 'valid',
                'alive' => $this->aliveValue($row['__alive'] ?? null),
                'last_seen_at' => $this->normalizeTimestamp($row['__seen'] ?? null),
                'source_table' => $table,
            ];
        }

        return $players;
    }

    /**
     * @return list<string>
     */
    private function columns(SQLite3 $db, string $table): array
    {
        $query = $db->query(sprintf("PRAGMA table_info('%s')", str_replace("'", "''", $table)));

        if (!$query) {
            return [];
        }

        $columns = [];

        while (($row = $query->fetchArray(SQLITE3_ASSOC)) !== false) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name !== '') {
                $columns[] = $name;
            }
        }

        return $columns;
    }

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

        if (is_numeric($value)) {
            return ((int) $value) > 0;
        }

        $text = strtolower(trim((string) $value));

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

        $text = trim((string) $value);
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
