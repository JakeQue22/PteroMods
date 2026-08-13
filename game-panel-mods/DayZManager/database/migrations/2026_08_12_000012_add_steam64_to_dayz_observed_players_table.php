<?php

declare(strict_types=1);

return [
    'up' => [
        "ALTER TABLE `dayz_observed_players`
            ADD COLUMN IF NOT EXISTS `steam64` VARCHAR(64) NULL DEFAULT NULL AFTER `player_id`,
            ADD KEY IF NOT EXISTS `idx_dayz_observed_players_steam64` (`steam64`);",
    ],
    'down' => [
        "ALTER TABLE `dayz_observed_players`
            DROP KEY IF EXISTS `idx_dayz_observed_players_steam64`,
            DROP COLUMN IF EXISTS `steam64`;",
    ],
];
