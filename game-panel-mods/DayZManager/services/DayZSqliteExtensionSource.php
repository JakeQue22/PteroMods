<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use SQLite3;
use SQLite3Result;
use Throwable;

/**
 * Reads a SQLite database file through the native `sqlite3` PHP extension.
 */
final class DayZSqliteExtensionSource implements DayZSqliteSource
{
    private function __construct(private readonly SQLite3 $database)
    {
    }

    public static function open(string $path): ?self
    {
        if (!class_exists(SQLite3::class)) {
            return null;
        }

        try {
            return new self(new SQLite3($path, SQLITE3_OPEN_READONLY));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return $this->column(
            $this->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"),
            'name',
        );
    }

    /**
     * @return list<string>
     */
    public function columns(string $table): array
    {
        return $this->column(
            $this->query(sprintf("PRAGMA table_info('%s')", str_replace("'", "''", $table))),
            'name',
        );
    }

    /**
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    public function rows(string $table, array $columns, int $limit): array
    {
        if ($columns === [] || $limit < 1) {
            return [];
        }

        $select = array_map(
            static fn (string $column): string => '"' . str_replace('"', '""', $column) . '"',
            $columns,
        );

        $result = $this->query(sprintf(
            'SELECT %s FROM "%s" LIMIT %d',
            implode(', ', $select),
            str_replace('"', '""', $table),
            $limit,
        ));

        if ($result === null) {
            return [];
        }

        $rows = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function close(): void
    {
        try {
            $this->database->close();
        } catch (Throwable) {
            // Already closed.
        }
    }

    private function query(string $sql): ?SQLite3Result
    {
        try {
            $result = @$this->database->query($sql);
        } catch (Throwable) {
            return null;
        }

        return $result === false ? null : $result;
    }

    /**
     * @return list<string>
     */
    private function column(?SQLite3Result $result, string $key): array
    {
        if ($result === null) {
            return [];
        }

        $values = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $value = trim((string) ($row[$key] ?? ''));

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}
