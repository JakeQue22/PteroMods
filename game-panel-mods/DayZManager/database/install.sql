-- PteroMods – DayZ Manager full install schema
-- Compatible with MySQL 5.7+ / MariaDB 10.3+
-- Usage: mysql -u root -p pterodactyl < install.sql

CREATE TABLE IF NOT EXISTS `dayz_mods` (
    `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `workshop_id`    VARCHAR(32)      NOT NULL,
    `title`          VARCHAR(255)     NOT NULL,
    `folder_name`    VARCHAR(255)     NOT NULL,
    `enabled`        TINYINT(1)       NOT NULL DEFAULT 1,
    `version`        VARCHAR(64)      NOT NULL,
    `latest_version` VARCHAR(64)      NOT NULL,
    `dependencies`   TEXT             NOT NULL,
    `position`       INT              NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dayz_mods_workshop_id` (`workshop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dayz_configuration_backups` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `path`       VARCHAR(255)  NOT NULL,
    `content`    MEDIUMTEXT    NOT NULL,
    `created_at` TIMESTAMP     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_dayz_configuration_backups_path` (`path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dayz_player_lists` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `list_type`  ENUM('ban','whitelist','priority') NOT NULL,
    `player_id`  VARCHAR(64)   NOT NULL COMMENT 'Steam64 ID or GUID',
    `note`       VARCHAR(255)  NOT NULL DEFAULT '',
    `added_by`   VARCHAR(64)   NOT NULL DEFAULT '',
    `created_at` TIMESTAMP     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dayz_player_lists_type_player` (`list_type`, `player_id`),
    KEY `idx_dayz_player_lists_type` (`list_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
