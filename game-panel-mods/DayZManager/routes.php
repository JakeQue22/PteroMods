<?php

declare(strict_types=1);

return [
    ['method' => 'GET', 'uri' => '/servers/{server}/dayz', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZDashboardController@show'],
    ['method' => 'GET', 'uri' => '/servers/{server}/dayz/mods', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZWorkshopController@index'],
    ['method' => 'GET', 'uri' => '/servers/{server}/dayz/configuration', 'action' => 'GamePanelMods\\DayZManager\\Controllers\\DayZConfigurationController@index'],
];
