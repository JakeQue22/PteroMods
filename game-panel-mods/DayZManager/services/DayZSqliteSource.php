<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

/**
 * Minimal read-only access to a SQLite database file.
 *
 * DayZ persistence files are read through one of three interchangeable
 * implementations, so the panel can inspect `characters.db` / `players.db`
 * regardless of which SQLite driver (if any) the panel PHP runtime ships.
 */
interface DayZSqliteSource
{
    /**
     * Names of the user tables in the database.
     *
     * @return list<string>
     */
    public function tables(): array;

    /**
     * Column names of a table, in declaration order.
     *
     * @return list<string>
     */
    public function columns(string $table): array;

    /**
     * Reads up to `$limit` rows, restricted to the requested columns.
     *
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    public function rows(string $table, array $columns, int $limit): array;

    /**
     * Releases any resources held by the source.
     */
    public function close(): void;
}
