<?php

declare(strict_types=1);

return [
    'up' => [
        "ALTER TABLE `dayz_restart_schedules`
            ADD COLUMN IF NOT EXISTS `start_time`      VARCHAR(5)  NOT NULL DEFAULT '' AFTER `interval_minutes`,
            ADD COLUMN IF NOT EXISTS `last_restart_at` TIMESTAMP   NULL DEFAULT NULL AFTER `next_restart_at`;",
    ],
    'down' => [
        "ALTER TABLE `dayz_restart_schedules`
            DROP COLUMN IF EXISTS `start_time`,
            DROP COLUMN IF EXISTS `last_restart_at`;",
    ],
];
