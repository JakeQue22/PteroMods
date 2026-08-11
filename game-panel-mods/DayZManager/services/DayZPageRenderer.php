<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Renders DayZ Manager pages inside a self-contained, panel-themed HTML layout.
 *
 * The panel client area is a JavaScript application that does not expose its
 * stylesheet to server-rendered module routes, so every module page ships the
 * module stylesheet inline. That keeps the pages readable and correctly laid
 * out regardless of the panel version.
 */
final class DayZPageRenderer
{
    private const TABS = [
        ['key' => 'dashboard',     'label' => 'Dashboard',     'path' => ''],
        ['key' => 'mods',          'label' => 'Workshop Mods', 'path' => '/mods'],
        ['key' => 'players',       'label' => 'Player Lists',  'path' => '/players'],
        ['key' => 'server',        'label' => 'Server Control', 'path' => '/server'],
        ['key' => 'configuration', 'label' => 'Configuration', 'path' => '/configuration'],
        ['key' => 'dzsa',          'label' => 'DZSA Launcher',  'path' => '/dzsa'],
        ['key' => 'live-map',      'label' => 'Live Map',       'path' => '/live-map'],
        ['key' => 'backups',       'label' => 'Backups',        'path' => '/backups'],
        ['key' => 'settings',      'label' => 'Settings',       'path' => '/settings'],
    ];

    /**
     * Renders a module view file wrapped in the module layout.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $view, array $data, string $activeTab, string $serverId, string $serverName = 'DayZ Server'): mixed
    {
        if (!$this->viewsAvailable()) {
            return $data;
        }

        $base = $this->basePath($serverId);
        $data['server_id'] = $serverId;
        $data['base_url'] = $base;
        // Panel file-manager deep links always use the client identifier, even
        // when the page was opened from the admin area.
        $data['client_id'] ??= $serverId;
        // Blade's @include resolves view names, not file paths, so views render
        // shared components through this callable instead.
        $data['component'] = fn (string $name, array $componentData = []): string => $this->component($name, $componentData);

        try {
            $content = $this->renderFile($this->viewPath($view), $data);
        } catch (Throwable $exception) {
            $content = $this->errorMarkup($exception->getMessage());
        }

        return $this->respond($this->wrap($content, $activeTab, $serverId, $serverName));
    }

    /**
     * Renders a standalone error page using the module layout.
     */
    public function renderError(string $message, string $activeTab, string $serverId, string $serverName = 'DayZ Server'): mixed
    {
        if (!$this->viewsAvailable()) {
            return ['error' => $message];
        }

        return $this->respond($this->wrap($this->errorMarkup($message), $activeTab, $serverId, $serverName), 500);
    }

    /**
     * Renders a single component view file and returns its HTML.
     *
     * @param array<string, mixed> $data
     */
    public function component(string $component, array $data): string
    {
        return $this->renderFile(__DIR__ . '/../components/' . $component . '.blade.php', $data);
    }

    /**
     * Base URL for every DayZ Manager page of a server.
     *
     * Admin requests keep the admin prefix so the tab stays inside the admin
     * area (and the numeric server id in the URL keeps working).
     */
    public function basePath(string $serverId): string
    {
        return $this->serverPath($serverId) . '/dayz';
    }

    /**
     * URL of the panel page the module pages link back to.
     */
    public function serverPath(string $serverId): string
    {
        $encoded = rawurlencode($serverId);

        return $this->isAdminRequest()
            ? '/admin/servers/view/' . $encoded
            : '/server/' . $encoded;
    }

    /**
     * True when the current request was made from the admin area.
     */
    private function isAdminRequest(): bool
    {
        if (!function_exists('request')) {
            return false;
        }

        try {
            $request = request();

            return is_object($request)
                && method_exists($request, 'path')
                && str_starts_with('/' . ltrim((string) $request->path(), '/'), '/admin/');
        } catch (Throwable) {
            return false;
        }
    }

    private function wrap(string $content, string $activeTab, string $serverId, string $serverName): string
    {
        $base = $this->basePath($serverId);

        $tabs = array_map(
            static fn (array $tab): array => $tab + ['url' => $base . $tab['path']],
            self::TABS,
        );

        return $this->renderFile($this->viewPath('layout'), [
            'title'       => $this->tabLabel($activeTab),
            'server_name' => $serverName,
            'server_id'   => $serverId,
            'server_url'  => $this->serverPath($serverId),
            'active_tab'  => $activeTab,
            'tabs'        => $tabs,
            'content'     => $content,
            'module_css'  => $this->moduleCss(),
            'csrf_token'  => $this->csrfToken(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderFile(string $file, array $data): string
    {
        return (string) view()->file($file, $data)->render();
    }

    private function viewPath(string $view): string
    {
        return __DIR__ . '/../views/' . $view . '.blade.php';
    }

    private function tabLabel(string $activeTab): string
    {
        foreach (self::TABS as $tab) {
            if ($tab['key'] === $activeTab) {
                return $tab['label'];
            }
        }

        return 'DayZ Manager';
    }

    private function moduleCss(): string
    {
        $cssFile = __DIR__ . '/../assets/dayz-manager.css';
        $css = is_file($cssFile) ? file_get_contents($cssFile) : '';

        return $css === false ? '' : $css;
    }

    private function csrfToken(): string
    {
        if (!function_exists('csrf_token')) {
            return '';
        }

        try {
            return (string) csrf_token();
        } catch (Throwable) {
            return '';
        }
    }

    private function errorMarkup(string $message): string
    {
        return '<div class="dz-alert">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    private function respond(string $html, int $status = 200): mixed
    {
        if (function_exists('response')) {
            return response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        return $html;
    }

    private function viewsAvailable(): bool
    {
        return function_exists('view');
    }
}
