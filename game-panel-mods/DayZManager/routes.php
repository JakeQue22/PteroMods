<?php

declare(strict_types=1);

$pages = [
    ''               => 'DayZDashboardController@show',
    '/mods'          => 'DayZWorkshopController@index',
    '/configuration' => 'DayZConfigurationController@index',
    '/logs'          => 'DayZLogsController@index',
    '/players'       => 'DayZPlayerController@index',
    '/server'        => 'DayZServerController@launchParameters',
    '/dzsa'          => 'DayZServerController@dzsa',
    '/live-map'      => 'DayZLiveMapController@index',
    '/backups'       => 'DayZBackupController@index',
    '/settings'      => 'DayZManagerSettingsController@index',
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

// POST route for saving settings from the HTML page.
foreach (['/servers/{server}/dayz', '/server/{server}/dayz', '/admin/servers/view/{server}/dayz'] as $prefix) {
    $routes[] = [
        'method' => 'POST',
        'uri'    => $prefix . '/settings/save',
        'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZManagerSettingsController@save',
    ];
}

return $routes;
