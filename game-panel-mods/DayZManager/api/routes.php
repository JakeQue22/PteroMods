<?php

declare(strict_types=1);

return [
    ['method' => 'GET', 'uri' => '/api/servers/{server}/dayz/mods', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@index'],
    ['method' => 'GET', 'uri' => '/api/servers/{server}/dayz/configuration', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZConfigurationController@index'],
    ['method' => 'POST', 'uri' => '/api/servers/{server}/dayz/mods/install', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@install'],
    ['method' => 'POST', 'uri' => '/api/servers/{server}/dayz/mods/remove', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@remove'],
    ['method' => 'POST', 'uri' => '/api/servers/{server}/dayz/mods/update', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@update'],
    ['method' => 'POST', 'uri' => '/api/servers/{server}/dayz/mods/enable', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@enable'],
    ['method' => 'POST', 'uri' => '/api/servers/{server}/dayz/mods/disable', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@disable'],
    ['method' => 'PUT', 'uri' => '/api/servers/{server}/dayz/configuration', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZConfigurationController@save'],
];
