<?php

declare(strict_types=1);

$pages = [
    ''               => 'DayZDashboardController@show',
    '/mods'          => 'DayZWorkshopController@index',
    '/configuration' => 'DayZConfigurationController@index',
    '/players'       => 'DayZPlayerController@index',
    '/server'        => 'DayZServerController@launchParameters',
];

$routes = [];

// The panel uses `/server/{id}` in the client area and `/admin/servers/view/{id}`
// in the admin area; both (plus the plural `/servers/{id}` alias) reach the same
// pages so the DayZ Manager tab works wherever it is opened from.
foreach (['/servers/{server}/dayz', '/server/{server}/dayz', '/admin/servers/view/{server}/dayz'] as $prefix) {
    foreach ($pages as $suffix => $action) {
        $routes[] = [
            'method' => 'GET',
            'uri'    => $prefix . $suffix,
            'action' => 'GamePanelMods\\DayZManager\\Controllers\\' . $action,
        ];
    }
}

$routes[] = [
    'method' => 'GET',
    'uri'    => '/game-panel-mods/dayz-manager/tab.js',
    'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZTabController@script',
];

return $routes;
