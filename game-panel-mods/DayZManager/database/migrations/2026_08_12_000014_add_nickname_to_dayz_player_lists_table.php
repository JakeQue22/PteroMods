<?php

declare(strict_types=1);

return [
    'up' => [
        "ALTER TABLE `dayz_player_lists`
            ADD COLUMN `nickname` VARCHAR(255) NOT NULL DEFAULT '' AFTER `player_id`;",
    ],
    'down' => [
        "ALTER TABLE `dayz_player_lists`
            DROP COLUMN `nickname`;",
    ],
];
