<?php

declare(strict_types=1);

$routes = [
    ['method' => 'POST',   'uri' => '/api/servers/{server}/dayz/player-actions/kick',       'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZPlayerController@kick'],
    ['method' => 'POST',   'uri' => '/api/server/{server}/dayz/player-actions/kick',        'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZPlayerController@kick'],
    ['method' => 'DELETE', 'uri' => '/api/servers/{server}/dayz/player-actions/observed/{id}', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZPlayerController@resetObserved'],
    ['method' => 'DELETE', 'uri' => '/api/server/{server}/dayz/player-actions/observed/{id}',  'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZPlayerController@resetObserved'],
    ['method' => 'GET',    'uri' => '/api/servers/{server}/dayz/mods',                     'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@index'],
    ['method' => 'POST',   'uri' => '/api/servers/{server}/dayz/mods/install',              'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@install'],
    ['method' => 'POST',   'uri' => '/api/servers/{server}/dayz/mods/remove',               'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@remove'],
    ['method' => 'POST',   'uri' => '/api/servers/{server}/dayz/mods/update',               'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@update'],
    ['method' => 'POST',   'uri' => '/api/servers/{server}/dayz/mods/enable',               'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@enable'],
    ['method' => 'POST',   'uri' => '/api/servers/{server}/dayz/mods/disable',              'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@disable'],
    ['method' => 'POST',   'uri' => '/api/servers/{server}/dayz/mods/reorder',              'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@reorder'],
    ['method' => 'GET',    'uri' => '/api/servers/{server}/dayz/configuration',             'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZConfigurationController@index'],
    ['method' => 'PUT',    'uri' => '/api/servers/{server}/dayz/configuration',             'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZConfigurationController@save'],
    ['method' => 'GET',    'uri' => '/api/servers/{server}/dayz/players/{list_type}',       'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZPlayerController@index'],
    ['method' => 'POST',   'uri' => '/api/servers/{server}/dayz/players/{list_type}',       'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZPlayerController@add'],
    ['method' => 'DELETE', 'uri' => '/api/servers/{server}/dayz/players/{list_type}/{id}',  'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZPlayerController@remove'],
    ['method' => 'POST',   'uri' => '/api/servers/{server}/dayz/server/restart',            'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZServerController@restart'],
    ['method' => 'GET',    'uri' => '/api/servers/{server}/dayz/server/launch-parameters',  'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZServerController@launchParameters'],
    ['method' => 'GET',    'uri' => '/api/server/{server}/dayz/mods',                       'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@index'],
    ['method' => 'POST',   'uri' => '/api/server/{server}/dayz/mods/install',               'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@install'],
    ['method' => 'POST',   'uri' => '/api/server/{server}/dayz/mods/remove',                'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@remove'],
    ['method' => 'POST',   'uri' => '/api/server/{server}/dayz/mods/update',                'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@update'],
    ['method' => 'POST',   'uri' => '/api/server/{server}/dayz/mods/enable',                'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@enable'],
    ['method' => 'POST',   'uri' => '/api/server/{server}/dayz/mods/disable',               'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@disable'],
    ['method' => 'POST',   'uri' => '/api/server/{server}/dayz/mods/reorder',               'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@reorder'],
    ['method' => 'GET',    'uri' => '/api/server/{server}/dayz/configuration',              'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZConfigurationController@index'],
    ['method' => 'PUT',    'uri' => '/api/server/{server}/dayz/configuration',              'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZConfigurationController@save'],
    ['method' => 'GET',    'uri' => '/api/server/{server}/dayz/players/{list_type}',        'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZPlayerController@index'],
    ['method' => 'POST',   'uri' => '/api/server/{server}/dayz/players/{list_type}',        'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZPlayerController@add'],
    ['method' => 'DELETE', 'uri' => '/api/server/{server}/dayz/players/{list_type}/{id}',   'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZPlayerController@remove'],
    ['method' => 'POST',   'uri' => '/api/server/{server}/dayz/server/restart',             'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZServerController@restart'],
    ['method' => 'GET',    'uri' => '/api/server/{server}/dayz/server/launch-parameters',   'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZServerController@launchParameters'],
];

foreach (['/api/servers/{server}/dayz', '/api/server/{server}/dayz'] as $prefix) {
    $routes[] = ['method' => 'POST',   'uri' => $prefix . '/players/superadmin',       'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZPlayerController@addSuperadmin'];
    $routes[] = ['method' => 'DELETE', 'uri' => $prefix . '/players/superadmin/{id}',   'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZPlayerController@removeSuperadmin'];
    $routes[] = ['method' => 'GET', 'uri' => $prefix . '/tab', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZTabController@status'];
    $routes[] = ['method' => 'GET', 'uri' => $prefix . '/dashboard', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZDashboardController@show'];
    $routes[] = ['method' => 'POST', 'uri' => $prefix . '/server/power', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZServerController@power'];
    $routes[] = ['method' => 'POST', 'uri' => $prefix . '/server/restart-schedule', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZServerController@saveRestartSchedule'];
    $routes[] = ['method' => 'POST', 'uri' => $prefix . '/server/restart-schedule/tick', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZServerController@tickRestartSchedule'];
    $routes[] = ['method' => 'POST', 'uri' => $prefix . '/server/mod-install-followup/tick', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZServerController@tickModInstallFollowUp'];
    $routes[] = ['method' => 'POST', 'uri' => $prefix . '/server/log-scrub/tick', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZServerController@tickProfileLogScrub'];
    $routes[] = ['method' => 'POST', 'uri' => $prefix . '/server/timed-restart',          'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZServerController@timedRestart'];
    $routes[] = ['method' => 'POST', 'uri' => $prefix . '/server/timed-restart/cancel',   'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZServerController@cancelTimedRestart'];
    $routes[] = ['method' => 'GET', 'uri' => $prefix . '/server/query-status', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZServerController@queryStatus'];
    $routes[] = ['method' => 'GET', 'uri' => $prefix . '/dzsa',               'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZServerController@dzsa'];
    $routes[] = ['method' => 'GET', 'uri' => $prefix . '/live-map',                  'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZLiveMapController@index'];
    $routes[] = ['method' => 'GET', 'uri' => $prefix . '/live-map/snapshot',          'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZLiveMapController@snapshot'];
    $routes[] = ['method' => 'GET', 'uri' => $prefix . '/live-map/markers',           'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZLiveMapController@markers'];
    $routes[] = ['method' => 'POST', 'uri' => $prefix . '/live-map/ingest',           'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZLiveMapController@ingest'];
    $routes[] = ['method' => 'POST', 'uri' => $prefix . '/live-map/setup-bridge',     'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZLiveMapController@setupBridge'];
    $routes[] = ['method' => 'POST', 'uri' => $prefix . '/live-map/remove-bridge',    'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZLiveMapController@removeBridge'];
    $routes[] = ['method' => 'GET',  'uri' => $prefix . '/live-map/bridge-status',    'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZLiveMapController@bridgeStatus'];
    $routes[] = ['method' => 'GET', 'uri' => $prefix . '/mods/install/status', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@installStatus'];
    $routes[] = ['method' => 'GET', 'uri' => $prefix . '/mods/install/queue', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@queue'];
    $routes[] = ['method' => 'GET', 'uri' => $prefix . '/mods/lookup', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@lookup'];
    $routes[] = ['method' => 'GET', 'uri' => $prefix . '/mods/browse', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@browse'];
    $routes[] = ['method' => 'GET',    'uri' => $prefix . '/settings',                'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZManagerSettingsController@index'];
    $routes[] = ['method' => 'POST',   'uri' => $prefix . '/settings/save',           'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZManagerSettingsController@save'];
    $routes[] = ['method' => 'GET',    'uri' => $prefix . '/backups',                 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZBackupController@index'];
    $routes[] = ['method' => 'POST',   'uri' => $prefix . '/backups/create',          'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZBackupController@create'];
    $routes[] = ['method' => 'POST',   'uri' => $prefix . '/backups/restore',         'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZBackupController@restore'];
    $routes[] = ['method' => 'DELETE', 'uri' => $prefix . '/backups/{backup_id}',     'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZBackupController@delete'];
    $routes[] = ['method' => 'POST',   'uri' => $prefix . '/backups/tick',            'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZBackupController@tick'];
    $routes[] = ['method' => 'POST',   'uri' => $prefix . '/backups/settings',        'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZBackupController@saveSettings'];
}

return $routes;
