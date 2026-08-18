<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS `dayz_restart_schedules` (
            `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `server_id`        VARCHAR(64)   NOT NULL,
            `enabled`          TINYINT(1)    NOT NULL DEFAULT 0,
            `interval_minutes` INT           NOT NULL DEFAULT 360,
            `next_restart_at`  TIMESTAMP     NULL DEFAULT NULL,
            `warnings_sent`    VARCHAR(255)  NOT NULL DEFAULT '',
            `warning_minutes_enabled` VARCHAR(255) NOT NULL DEFAULT '180,120,60,30,20,15,10,5,2,1',
            `warning_messages` TEXT           NULL,
            `created_at`       TIMESTAMP     NULL DEFAULT NULL,
            `updated_at`       TIMESTAMP     NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_dayz_restart_schedules_server` (`server_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'down' => [
        'DROP TABLE IF EXISTS `dayz_restart_schedules`;',
    ],
];
