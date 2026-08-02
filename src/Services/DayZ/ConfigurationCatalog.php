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
     * File extensions the panel file editor can open, mapped to the syntax
     * highlighting mode it uses for them.
     */
    private const EDITOR_LANGUAGES = [
        'cfg'  => 'ini',
        'ini'  => 'ini',
        'conf' => 'ini',
        'xml'  => 'xml',
        'json' => 'json',
        'txt'  => 'plaintext',
        'log'  => 'plaintext',
        'md'   => 'markdown',
        'bat'  => 'batch',
        'sh'   => 'shell',
        'c'    => 'c_cpp',
        'cpp'  => 'c_cpp',
        'h'    => 'c_cpp',
        'hpp'  => 'c_cpp',
        'yml'  => 'yaml',
        'yaml' => 'yaml',
    ];

    /**
     * Extensions that are edited as prose rather than code.
     */
    private const TEXT_EXTENSIONS = ['txt', 'log', 'md'];

    /**
     * Describes a single configuration file: its category, the editor that
     * should open it, and whether the panel file editor supports it at all.
     *
     * @return array{path: string, name: string, category: string, extension: string, language: string, editor: string, editable: bool}
     */
    public function describe(string $path): array
    {
        $name = basename($path);
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $language = self::EDITOR_LANGUAGES[$extension] ?? '';
        $editable = $language !== '' || $extension === '';

        return [
            'path'      => $path,
            'name'      => $name,
            'category'  => $this->categoryFor($name),
            'extension' => $extension,
            'language'  => $language !== '' ? $language : 'plaintext',
            'editor'    => match (true) {
                !$editable => 'download',
                in_array($extension, self::TEXT_EXTENSIONS, true) || $extension === '' => 'text editor',
                default => 'code editor',
            },
            'editable'  => $editable,
        ];
    }

    /**
     * Resolves the operator-facing category of a single file name.
     */
    public function categoryFor(string $name): string
    {
        $name = basename($name);

        if (in_array($name, self::SERVER_MESSAGE_NAMES, true)) {
            return 'Server Messages';
        }

        if (in_array($name, self::ADMIN_TOOL_NAMES, true)) {
            return 'Admin Tools';
        }

        return 'Default';
    }

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
