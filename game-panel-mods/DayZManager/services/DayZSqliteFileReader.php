<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Dependency-free, read-only SQLite database file reader.
 *
 * Many Pterodactyl panel images ship a PHP runtime without the `sqlite3`
 * extension *and* without the `pdo_sqlite` driver, which made DayZ persistence
 * files (`characters.db` / `players.db`) unreadable.  This reader parses the
 * SQLite file format directly — database header, table b-tree pages, overflow
 * chains, and record serial types — so player records can always be listed.
 *
 * Only the subset of the format needed to list rows is implemented: table
 * b-trees (with and without rowid), the standard text encodings, and overflow
 * payloads.  Nothing is ever written back to the file.
 *
 * @see https://www.sqlite.org/fileformat2.html
 */
final class DayZSqliteFileReader implements DayZSqliteSource
{
    private const HEADER_MAGIC = "SQLite format 3\0";
    private const MAX_OVERFLOW_PAGES = 4096;
    private const MAX_SCHEMA_ROWS = 5000;

    /** Page types. */
    private const PAGE_INTERIOR_INDEX = 0x02;
    private const PAGE_INTERIOR_TABLE = 0x05;
    private const PAGE_LEAF_INDEX = 0x0a;
    private const PAGE_LEAF_TABLE = 0x0d;

    /** @var array<string, array{root: int, columns: list<string>, rowid_alias: int|null}> */
    private array $schema = [];

    /**
     * @param string $data Raw database file contents.
     */
    private function __construct(
        private readonly string $data,
        private readonly int $pageSize,
        private readonly int $usable,
        private readonly int $encoding,
    ) {
        $this->loadSchema();
    }

    /**
     * Opens a database file, or returns null when it is not a usable SQLite file.
     */
    public static function open(string $path): ?self
    {
        $data = @file_get_contents($path);

        return is_string($data) ? self::fromString($data) : null;
    }

    /**
     * Opens an in-memory database image, or returns null when it is not usable.
     */
    public static function fromString(string $data): ?self
    {
        if (strlen($data) < 100 || !str_starts_with($data, self::HEADER_MAGIC)) {
            return null;
        }

        $pageSize = (int) (unpack('n', substr($data, 16, 2))[1] ?? 0);

        if ($pageSize === 1) {
            $pageSize = 65536;
        }

        if ($pageSize < 512 || ($pageSize & ($pageSize - 1)) !== 0) {
            return null;
        }

        $usable = $pageSize - ord($data[20]);

        if ($usable < 480 || strlen($data) < $pageSize) {
            return null;
        }

        $encoding = (int) (unpack('N', substr($data, 56, 4))[1] ?? 1);

        try {
            return new self($data, $pageSize, $usable, in_array($encoding, [1, 2, 3], true) ? $encoding : 1);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return array_keys($this->schema);
    }

    /**
     * @return list<string>
     */
    public function columns(string $table): array
    {
        return $this->schema[$table]['columns'] ?? [];
    }

    /**
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    public function rows(string $table, array $columns, int $limit): array
    {
        $definition = $this->schema[$table] ?? null;

        if ($definition === null || $limit < 1) {
            return [];
        }

        $indexes = [];

        foreach ($columns as $column) {
            $index = array_search(strtolower($column), array_map('strtolower', $definition['columns']), true);

            if ($index !== false) {
                $indexes[$column] = (int) $index;
            }
        }

        if ($indexes === []) {
            return [];
        }

        $rowidAlias = $definition['rowid_alias'];
        $rows = [];

        $this->scanTree(
            $definition['root'],
            array_values($indexes),
            $limit,
            static function (array $values, int $rowid) use (&$rows, $indexes, $rowidAlias): bool {
                $row = [];

                foreach ($indexes as $column => $index) {
                    $value = $values[$index] ?? null;

                    // `INTEGER PRIMARY KEY` columns are stored as NULL in the
                    // record; their value is the rowid of the entry.
                    if ($value === null && $rowidAlias === $index) {
                        $value = $rowid;
                    }

                    $row[$column] = $value;
                }

                $rows[] = $row;

                return true;
            },
        );

        return $rows;
    }

    public function close(): void
    {
        $this->schema = [];
    }

    // ── Schema ────────────────────────────────────────────────────────────────

    private function loadSchema(): void
    {
        $schema = [];

        // sqlite_master: type(0), name(1), tbl_name(2), rootpage(3), sql(4).
        $this->scanTree(1, [0, 1, 3, 4], self::MAX_SCHEMA_ROWS, static function (array $values) use (&$schema): bool {
            if (strtolower(trim((string) ($values[0] ?? ''))) !== 'table') {
                return true;
            }

            $name = trim((string) ($values[1] ?? ''));
            $root = (int) ($values[3] ?? 0);

            if ($name === '' || $root < 1 || str_starts_with(strtolower($name), 'sqlite_')) {
                return true;
            }

            $parsed = self::parseCreateTable((string) ($values[4] ?? ''));

            if ($parsed['columns'] === []) {
                return true;
            }

            $schema[$name] = ['root' => $root] + $parsed;

            return true;
        });

        $this->schema = $schema;
    }

    /**
     * Extracts column names (and any rowid alias) from a `CREATE TABLE` statement.
     *
     * @return array{columns: list<string>, rowid_alias: int|null}
     */
    private static function parseCreateTable(string $sql): array
    {
        $start = strpos($sql, '(');
        $end = strrpos($sql, ')');

        if ($start === false || $end === false || $end <= $start) {
            return ['columns' => [], 'rowid_alias' => null];
        }

        $columns = [];
        $rowidAlias = null;

        foreach (self::splitDefinitions(substr($sql, $start + 1, $end - $start - 1)) as $definition) {
            $definition = trim($definition);

            if ($definition === '' || preg_match('/^(constraint|primary|unique|check|foreign|key)\b/i', $definition) === 1) {
                continue;
            }

            $name = self::firstIdentifier($definition);

            if ($name === null) {
                continue;
            }

            if (preg_match('/\binteger\b\s+primary\s+key\b/i', $definition) === 1) {
                $rowidAlias = count($columns);
            }

            $columns[] = $name;
        }

        return ['columns' => $columns, 'rowid_alias' => $rowidAlias];
    }

    /**
     * Splits a column definition list on top-level commas.
     *
     * @return list<string>
     */
    private static function splitDefinitions(string $body): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $closing = null;
        $length = strlen($body);

        for ($index = 0; $index < $length; $index++) {
            $character = $body[$index];

            if ($closing !== null) {
                $current .= $character;

                if ($character === $closing) {
                    // A doubled quote inside a quoted identifier is an escape.
                    if ($closing !== ']' && ($body[$index + 1] ?? '') === $closing) {
                        $current .= $closing;
                        $index++;
                        continue;
                    }

                    $closing = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'" || $character === '`') {
                $closing = $character;
            } elseif ($character === '[') {
                $closing = ']';
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $character;
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    private static function firstIdentifier(string $definition): ?string
    {
        $first = $definition[0];

        if ($first === '"' || $first === "'" || $first === '`' || $first === '[') {
            $closing = $first === '[' ? ']' : $first;
            $end = strpos($definition, $closing, 1);

            if ($end === false) {
                return null;
            }

            $name = trim(str_replace($closing . $closing, $closing, substr($definition, 1, $end - 1)));

            return $name === '' ? null : $name;
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_$]*/', $definition, $matches) === 1 ? $matches[0] : null;
    }

    // ── B-tree traversal ──────────────────────────────────────────────────────

    /**
     * Walks a table b-tree and hands every decoded record to `$callback`.
     *
     * @param list<int>|null $wanted   Record column indexes to materialise, or null for all.
     * @param callable(array<int, mixed>, int): bool $callback Return false to stop early.
     */
    private function scanTree(int $root, ?array $wanted, int $limit, callable $callback): void
    {
        if ($root < 1 || $limit < 1) {
            return;
        }

        $stack = [$root];
        $visited = [];
        $emitted = 0;

        while ($stack !== [] && $emitted < $limit) {
            $number = (int) array_pop($stack);

            if ($number < 1 || isset($visited[$number])) {
                continue;
            }

            $visited[$number] = true;
            $page = $this->page($number);

            if ($page === null) {
                continue;
            }

            $offset = $number === 1 ? 100 : 0;
            $type = ord($page[$offset]);
            $cellCount = $this->uint16($page, $offset + 3);
            $interior = $type === self::PAGE_INTERIOR_TABLE || $type === self::PAGE_INTERIOR_INDEX;
            $pointers = $offset + ($interior ? 12 : 8);

            if ($interior) {
                $children = [];

                for ($cell = 0; $cell < $cellCount; $cell++) {
                    $cellOffset = $this->uint16($page, $pointers + ($cell * 2));

                    if ($cellOffset > 0 && $cellOffset + 4 <= $this->usable) {
                        $children[] = $this->uint32($page, $cellOffset);
                    }
                }

                $children[] = $this->uint32($page, $offset + 8);

                for ($index = count($children) - 1; $index >= 0; $index--) {
                    if ($children[$index] > 0) {
                        $stack[] = $children[$index];
                    }
                }

                continue;
            }

            if ($type !== self::PAGE_LEAF_TABLE && $type !== self::PAGE_LEAF_INDEX) {
                continue;
            }

            for ($cell = 0; $cell < $cellCount && $emitted < $limit; $cell++) {
                $cellOffset = $this->uint16($page, $pointers + ($cell * 2));

                if ($cellOffset < 1 || $cellOffset >= $this->usable) {
                    continue;
                }

                $parsed = $this->readCell($page, $cellOffset, $type);

                if ($parsed === null) {
                    continue;
                }

                $values = $this->decodeRecord($parsed, $wanted);

                if ($values === null) {
                    continue;
                }

                $emitted++;

                if ($callback($values, $parsed['rowid']) === false) {
                    return;
                }
            }
        }
    }

    /**
     * @return array{rowid: int, local: string, size: int, overflow: int}|null
     */
    private function readCell(string $page, int $offset, int $type): ?array
    {
        [$size, $position] = $this->varint($page, $offset);
        $rowid = 0;

        if ($type === self::PAGE_LEAF_TABLE) {
            [$rowid, $position] = $this->varint($page, $position);
        }

        if ($size < 1) {
            return null;
        }

        $maxLocal = $type === self::PAGE_LEAF_TABLE
            ? $this->usable - 35
            : intdiv(($this->usable - 12) * 64, 255) - 23;

        $overflow = 0;

        if ($size <= $maxLocal) {
            $local = $size;
        } else {
            $minLocal = intdiv(($this->usable - 12) * 32, 255) - 23;
            $threshold = $minLocal + (($size - $minLocal) % ($this->usable - 4));
            $local = $threshold <= $maxLocal ? $threshold : $minLocal;

            if ($position + $local + 4 > $this->usable) {
                return null;
            }

            $overflow = $this->uint32($page, $position + $local);
        }

        if ($local < 1 || $position + $local > $this->usable) {
            return null;
        }

        return [
            'rowid' => $rowid,
            'local' => substr($page, $position, $local),
            'size' => $size,
            'overflow' => $overflow,
        ];
    }

    /**
     * Decodes a record payload, following the overflow chain only when a
     * requested column actually lives beyond the locally stored bytes.
     *
     * @param array{rowid: int, local: string, size: int, overflow: int} $cell
     * @param list<int>|null $wanted
     * @return array<int, mixed>|null
     */
    private function decodeRecord(array $cell, ?array $wanted): ?array
    {
        $buffer = $cell['local'];
        $overflow = $cell['overflow'];
        $total = $cell['size'];

        $ensure = function (int $length) use (&$buffer, &$overflow, $total): bool {
            $length = min($length, $total);
            $guard = 0;

            while (strlen($buffer) < $length && $overflow > 0 && $guard++ < self::MAX_OVERFLOW_PAGES) {
                $page = $this->page($overflow);

                if ($page === null) {
                    $overflow = 0;
                    break;
                }

                $overflow = $this->uint32($page, 0);
                $buffer .= substr($page, 4, $this->usable - 4);
            }

            return strlen($buffer) >= $length;
        };

        [$headerSize, $position] = $this->varint($buffer, 0);

        if ($headerSize < 1 || $headerSize > $total || !$ensure($headerSize)) {
            return null;
        }

        $serials = [];

        while ($position < $headerSize) {
            [$serial, $position] = $this->varint($buffer, $position);
            $serials[] = $serial;
        }

        $values = [];
        $offset = $headerSize;

        foreach ($serials as $index => $serial) {
            $length = $this->serialLength($serial);

            if ($wanted === null || in_array($index, $wanted, true)) {
                if (!$ensure($offset + $length)) {
                    break;
                }

                $values[$index] = $this->decodeValue($serial, substr($buffer, $offset, $length));
            }

            $offset += $length;
        }

        return $values;
    }

    private function serialLength(int $serial): int
    {
        return match (true) {
            $serial === 0, $serial === 8, $serial === 9, $serial === 10, $serial === 11 => 0,
            $serial <= 4 => $serial,
            $serial === 5 => 6,
            $serial === 6, $serial === 7 => 8,
            ($serial % 2) === 0 => intdiv($serial - 12, 2),
            default => intdiv($serial - 13, 2),
        };
    }

    private function decodeValue(int $serial, string $bytes): mixed
    {
        return match (true) {
            $serial === 0 => null,
            $serial === 8 => 0,
            $serial === 9 => 1,
            $serial === 10, $serial === 11 => null,
            $serial === 7 => (float) (unpack('E', $bytes)[1] ?? 0.0),
            $serial <= 6 => $this->signedInteger($bytes),
            ($serial % 2) === 0 => $bytes,
            default => $this->decodeText($bytes),
        };
    }

    private function signedInteger(string $bytes): int
    {
        $length = strlen($bytes);

        if ($length === 0) {
            return 0;
        }

        if ($length === 8) {
            // 'J' yields the raw 64-bit pattern, which is already the signed
            // value on the 64-bit platforms the panel runs on.
            return (int) (unpack('J', $bytes)[1] ?? 0);
        }

        $value = 0;

        for ($index = 0; $index < $length; $index++) {
            $value = ($value << 8) | ord($bytes[$index]);
        }

        $signBit = 1 << (($length * 8) - 1);

        return $value >= $signBit ? $value - ($signBit << 1) : $value;
    }

    private function decodeText(string $bytes): string
    {
        if ($this->encoding === 1 || $bytes === '' || !function_exists('mb_convert_encoding')) {
            return $bytes;
        }

        $converted = @mb_convert_encoding($bytes, 'UTF-8', $this->encoding === 2 ? 'UTF-16LE' : 'UTF-16BE');

        return is_string($converted) ? $converted : $bytes;
    }

    // ── Low-level helpers ─────────────────────────────────────────────────────

    private function page(int $number): ?string
    {
        if ($number < 1) {
            return null;
        }

        $start = ($number - 1) * $this->pageSize;

        if ($start + $this->pageSize > strlen($this->data)) {
            return null;
        }

        return substr($this->data, $start, $this->pageSize);
    }

    /**
     * @return array{0: int, 1: int} Decoded value and the offset after it.
     */
    private function varint(string $data, int $offset): array
    {
        $value = 0;

        for ($index = 0; $index < 8; $index++) {
            if (!isset($data[$offset + $index])) {
                return [0, $offset + $index];
            }

            $byte = ord($data[$offset + $index]);

            if ($byte < 0x80) {
                return [($value << 7) | $byte, $offset + $index + 1];
            }

            $value = ($value << 7) | ($byte & 0x7f);
        }

        $ninth = isset($data[$offset + 8]) ? ord($data[$offset + 8]) : 0;

        return [($value << 8) | $ninth, $offset + 9];
    }

    private function uint16(string $page, int $offset): int
    {
        if (!isset($page[$offset + 1])) {
            return 0;
        }

        return (ord($page[$offset]) << 8) | ord($page[$offset + 1]);
    }

    private function uint32(string $page, int $offset): int
    {
        if (!isset($page[$offset + 3])) {
            return 0;
        }

        return (ord($page[$offset]) << 24)
            | (ord($page[$offset + 1]) << 16)
            | (ord($page[$offset + 2]) << 8)
            | ord($page[$offset + 3]);
    }
}
