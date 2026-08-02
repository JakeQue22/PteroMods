<?php

declare(strict_types=1);

return [
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
];
