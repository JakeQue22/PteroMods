<?php

declare(strict_types=1);

namespace PteroMods\Services\DayZ;

/**
 * Groups DayZ configuration files into operator-facing categories.
 */
final class ConfigurationCatalog
{
    private const DEFAULT_NAMES = [
        'serverDZ.cfg',
        'BEServer.cfg',
        'messages.xml',
        'priority.txt',
        'ban.txt',
        'whitelist.txt',
        'scripts.log',
        'storage_1',
        'storage_2',
    ];

    private const SERVER_MESSAGE_NAMES = [
        'Messages.bat',
        'messages.cfg',
        'settings.cfg',
    ];

    private const ADMIN_TOOL_NAMES = [
        'credentials.txt',
        'SuperAdmins.txt',
        'admins.xml',
    ];

    /**
     * @param list<string> $paths
     * @return array{Default: list<string>, 'Server Messages': list<string>, 'Admin Tools': list<string>}
     */
    public function categorize(array $paths): array
    {
        $catalog = [
            'Default' => [],
            'Server Messages' => [],
            'Admin Tools' => [],
        ];

        foreach ($paths as $path) {
            $name = basename($path);

            if (in_array($name, self::SERVER_MESSAGE_NAMES, true)) {
                $catalog['Server Messages'][] = $path;
                continue;
            }

            if (in_array($name, self::ADMIN_TOOL_NAMES, true)) {
                $catalog['Admin Tools'][] = $path;
                continue;
            }

            if (in_array($name, self::DEFAULT_NAMES, true) || preg_match('/\.(bat|cfg|xml|txt)$/i', $name) === 1) {
                $catalog['Default'][] = $path;
            }
        }

        return $catalog;
    }
}
