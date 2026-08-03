<?php

declare(strict_types=1);

return [
    'up' => [
        "ALTER TABLE `dayz_restart_schedules`
            ADD COLUMN IF NOT EXISTS `timed_restart_at`      TIMESTAMP     NULL DEFAULT NULL AFTER `next_restart_at`,
            ADD COLUMN IF NOT EXISTS `timed_warnings_sent`   VARCHAR(255)  NOT NULL DEFAULT '' AFTER `timed_restart_at`;",
    ],
    'down' => [
        "ALTER TABLE `dayz_restart_schedules`
            DROP COLUMN IF EXISTS `timed_restart_at`,
            DROP COLUMN IF EXISTS `timed_warnings_sent`;",
    ],
];
