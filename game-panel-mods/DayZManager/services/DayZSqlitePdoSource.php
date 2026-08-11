<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use PDO;
use Throwable;

/**
 * Reads a SQLite database file through PDO's `sqlite` driver.
 */
final class DayZSqlitePdoSource implements DayZSqliteSource
{
    private function __construct(private readonly PDO $connection)
    {
    }

    public static function open(string $path): ?self
    {
        if (!class_exists(PDO::class)) {
            return null;
        }

        try {
            if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
                return null;
            }

            return new self(new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]));
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
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
            'name',
        );
    }

    /**
     * @return list<string>
     */
    public function columns(string $table): array
    {
        return $this->column(sprintf("PRAGMA table_info('%s')", str_replace("'", "''", $table)), 'name');
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

        try {
            $statement = $this->connection->query(sprintf(
                'SELECT %s FROM "%s" LIMIT %d',
                implode(', ', $select),
                str_replace('"', '""', $table),
                $limit,
            ));

            return $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    public function close(): void
    {
        // PDO connections are released when the instance is garbage collected.
    }

    /**
     * @return list<string>
     */
    private function column(string $sql, string $key): array
    {
        try {
            $statement = $this->connection->query($sql);

            if ($statement === false) {
                return [];
            }

            $values = [];

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $value = trim((string) ($row[$key] ?? ''));

                if ($value !== '') {
                    $values[] = $value;
                }
            }

            return $values;
        } catch (Throwable) {
            return [];
        }
    }
}
