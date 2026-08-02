<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZDashboardService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * Produces the DayZ dashboard payload for supported servers.
 */
final class DayZDashboardController
{
    public function __construct(private readonly DayZDashboardService $service = new DayZDashboardService())
    {
    }

    /**
     * @return mixed
     */
    public function show(string $server = '')
    {
        $dashboard = $this->service->dashboard();
        $viewPath = dirname(__DIR__) . '/views/dashboard.blade.php';

        if (class_exists(Blade::class) && is_file($viewPath) && function_exists('response')) {
            $template = (string) file_get_contents($viewPath);
            $html = Blade::render($template, $dashboard, deleteCachedView: true);

            return response(new HtmlString($html), 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        return $dashboard;
    }
}
