<?php

declare(strict_types=1);

namespace PteroMods\Services\DayZ;

/**
 * Reads DayZ mod metadata out of the `meta.cpp` / `mod.cpp` files that ship
 * inside every Steam Workshop mod folder.
 *
 * Both files use the same simple `key = value;` syntax, so a single parser
 * covers them. Values are returned verbatim; missing keys are omitted.
 */
final class ModMetaParser
{
    private const KEYS = ['publishedid', 'name', 'author', 'version', 'timestamp', 'picture'];

    /**
     * @return array<string, string> Lower-cased keys mapped to their values.
     */
    public function parse(string $contents): array
    {
        if (trim($contents) === '') {
            return [];
        }

        $values = [];

        foreach (self::KEYS as $key) {
            $pattern = '/^\s*' . $key . '\s*=\s*("(?<quoted>[^"]*)"|(?<raw>[^;]*))\s*;/mi';

            if (preg_match($pattern, $contents, $matches) !== 1) {
                continue;
            }

            $value = trim($matches['quoted'] !== '' ? $matches['quoted'] : ($matches['raw'] ?? ''));

            if ($value !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * Extracts the ordered mod folder names from a `-mod=` launch parameter.
     *
     * Accepts a full startup command, a bare parameter, or a plain
     * semicolon/comma separated folder list.
     *
     * @return list<string>
     */
    public function parseModList(string $value, string $parameter = 'mod'): array
    {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        // The parameter may be quoted as a whole ("-mod=@A;@B"), quoted after
        // the equals sign (-mod="@A;@B"), or unquoted.
        if (preg_match('/-' . preg_quote($parameter, '/') . '=(?:"([^"]*)"|\'([^\']*)\'|([^"\'\s]*))/i', $value, $matches) === 1) {
            $value = ($matches[1] ?? '') !== ''
                ? $matches[1]
                : ((($matches[2] ?? '') !== '') ? $matches[2] : ($matches[3] ?? ''));
        } elseif (str_contains($value, '-mod=') || str_contains($value, '-serverMod=')) {
            // A startup command that does not carry the requested parameter.
            return [];
        }

        $folders = preg_split('/[;,]+/', $value) ?: [];
        $folders = array_map(
            static fn (string $folder): string => trim($folder, " \t\"'"),
            $folders,
        );

        return array_values(array_filter(
            $folders,
            static fn (string $folder): bool => $folder !== ''
                && !str_starts_with($folder, '-')
                && !str_contains($folder, '{'),
        ));
    }
}
