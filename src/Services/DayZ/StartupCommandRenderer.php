<?php

declare(strict_types=1);

namespace PteroMods\Services\DayZ;

/**
 * Renders a Pterodactyl startup command by substituting its `{{VARIABLE}}`
 * placeholders with the values configured for a server.
 *
 * Pterodactyl stores the startup command with placeholders and resolves them
 * only when Wings boots the container, so the panel database alone never shows
 * the parameters a DayZ server actually starts with.
 */
final class StartupCommandRenderer
{
    /**
     * @param array<string, string|int|null> $variables Environment variable values.
     */
    public function render(string $startup, array $variables): string
    {
        if (trim($startup) === '') {
            return '';
        }

        $normalized = [];

        foreach ($variables as $key => $value) {
            $normalized[strtoupper(trim((string) $key))] = $value === null ? '' : (string) $value;
        }

        return (string) preg_replace_callback(
            '/\{\{\s*(?:env\.)?([A-Za-z0-9_]+)\s*\}\}/',
            static fn (array $matches): string => $normalized[strtoupper($matches[1])] ?? $matches[0],
            $startup,
        );
    }

    /**
     * Splits a rendered startup command into individual parameters, keeping
     * quoted segments intact.
     *
     * @return list<string>
     */
    public function parameters(string $command): array
    {
        $parts = preg_split('/\s+(?=["\']?-)/', trim($command)) ?: [];

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $part): bool => $part !== '',
        ));
    }
}
