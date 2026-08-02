<?php

declare(strict_types=1);

return [
    ['method' => 'GET', 'uri' => '/servers/{server}/dayz',              'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZDashboardController@show'],
    ['method' => 'GET', 'uri' => '/servers/{server}/dayz/mods',         'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@index'],
    ['method' => 'GET', 'uri' => '/servers/{server}/dayz/configuration', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZConfigurationController@index'],
    ['method' => 'GET', 'uri' => '/servers/{server}/dayz/players',      'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZPlayerController@index'],
    ['method' => 'GET', 'uri' => '/servers/{server}/dayz/server',       'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZServerController@launchParameters'],
    ['method' => 'GET', 'uri' => '/server/{server}/dayz',               'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZDashboardController@show'],
    ['method' => 'GET', 'uri' => '/server/{server}/dayz/mods',          'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@index'],
    ['method' => 'GET', 'uri' => '/server/{server}/dayz/configuration', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZConfigurationController@index'],
    ['method' => 'GET', 'uri' => '/server/{server}/dayz/players',       'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZPlayerController@index'],
    ['method' => 'GET', 'uri' => '/server/{server}/dayz/server',        'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZServerController@launchParameters'],
];
