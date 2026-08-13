<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS `dayz_inventory_item_resolutions` (
            `id`                INT UNSIGNED   NOT NULL AUTO_INCREMENT,
            `lookup_type`       VARCHAR(32)    NOT NULL,
            `lookup_key`        VARCHAR(255)   NOT NULL,
            `lookup_normalized` VARCHAR(255)   NOT NULL,
            `classname`         VARCHAR(191)   NOT NULL DEFAULT '',
            `internal_id`       VARCHAR(191)   NOT NULL DEFAULT '',
            `canonical_name`    VARCHAR(255)   NOT NULL DEFAULT '',
            `wiki_title`        VARCHAR(255)   NOT NULL DEFAULT '',
            `wiki_url`          VARCHAR(500)   NOT NULL DEFAULT '',
            `image_url`         VARCHAR(500)   NOT NULL DEFAULT '',
            `image_name`        VARCHAR(255)   NOT NULL DEFAULT '',
            `variant`           VARCHAR(255)   NOT NULL DEFAULT '',
            `category`          VARCHAR(255)   NOT NULL DEFAULT '',
            `type`              VARCHAR(255)   NOT NULL DEFAULT '',
            `aliases_json`      LONGTEXT       NULL,
            `verification_json` LONGTEXT       NULL,
            `confidence`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `refreshed_at`      TIMESTAMP      NULL DEFAULT NULL,
            `created_at`        TIMESTAMP      NULL DEFAULT NULL,
            `updated_at`        TIMESTAMP      NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_dayz_inventory_item_resolutions_lookup` (`lookup_type`, `lookup_normalized`),
            KEY `idx_dayz_inventory_item_resolutions_classname` (`classname`),
            KEY `idx_dayz_inventory_item_resolutions_wiki_title` (`wiki_title`),
            KEY `idx_dayz_inventory_item_resolutions_refreshed` (`refreshed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    ],
    'down' => [
        'DROP TABLE IF EXISTS `dayz_inventory_item_resolutions`;',
    ],
];
