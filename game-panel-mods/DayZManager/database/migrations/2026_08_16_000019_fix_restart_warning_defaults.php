<?php

declare(strict_types=1);

return [
    'up' => [
        "UPDATE `dayz_restart_schedules`
            SET `warning_minutes_enabled` = '180,120,60,30,20,15,10,5,2,1'
            WHERE `warning_minutes_enabled` = '180,120,60,30,20,10,5,2,1';",
        "ALTER TABLE `dayz_restart_schedules`
            ALTER COLUMN `warning_minutes_enabled`
            SET DEFAULT '180,120,60,30,20,15,10,5,2,1';",
    ],
    'down' => [],
];
