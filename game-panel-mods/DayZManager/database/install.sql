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

CREATE TABLE IF NOT EXISTS `dayz_mod_install_queue` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `server_id`    VARCHAR(64)   NOT NULL,
    `workshop_id`  VARCHAR(32)   NOT NULL,
    `title`        VARCHAR(255)  NOT NULL DEFAULT '',
    `thumbnail`    VARCHAR(500)  NOT NULL DEFAULT '',
    `file_size`    VARCHAR(32)   NOT NULL DEFAULT '',
    `status`       VARCHAR(16)   NOT NULL DEFAULT 'queued',
    `position`     INT           NOT NULL DEFAULT 0,
    `created_at`   TIMESTAMP     NULL DEFAULT NULL,
    `updated_at`   TIMESTAMP     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dayz_mod_install_queue_server_workshop` (`server_id`, `workshop_id`),
    KEY `idx_dayz_mod_install_queue_server` (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dayz_player_lists` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `list_type`  ENUM('ban','whitelist','priority') NOT NULL,
    `player_id`  VARCHAR(64)   NOT NULL COMMENT 'Steam64 ID or GUID',
    `nickname`   VARCHAR(255)  NOT NULL DEFAULT '',
    `note`       VARCHAR(255)  NOT NULL DEFAULT '',
    `added_by`   VARCHAR(64)   NOT NULL DEFAULT '',
    `created_at` TIMESTAMP     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dayz_player_lists_type_player` (`list_type`, `player_id`),
    KEY `idx_dayz_player_lists_type` (`list_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dayz_restart_schedules` (
    `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `server_id`        VARCHAR(64)   NOT NULL,
    `enabled`          TINYINT(1)    NOT NULL DEFAULT 0,
    `interval_minutes` INT           NOT NULL DEFAULT 360,
    `start_time`       VARCHAR(5)    NOT NULL DEFAULT '',
    `next_restart_at`  TIMESTAMP     NULL DEFAULT NULL,
    `last_restart_at`  TIMESTAMP     NULL DEFAULT NULL,
    `timed_restart_at` TIMESTAMP     NULL DEFAULT NULL,
    `timed_warnings_sent` VARCHAR(255) NOT NULL DEFAULT '',
    `warnings_sent`    VARCHAR(255)  NOT NULL DEFAULT '',
    `warning_minutes_enabled` VARCHAR(255) NOT NULL DEFAULT '180,120,60,30,20,10,5,2,1',
    `warning_messages` TEXT           NULL,
    `created_at`       TIMESTAMP     NULL DEFAULT NULL,
    `updated_at`       TIMESTAMP     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dayz_restart_schedules_server` (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dayz_server_mod_order` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `server_id`   VARCHAR(64)   NOT NULL,
    `folder_name` VARCHAR(255)  NOT NULL,
    `position`    INT           NOT NULL DEFAULT 0,
    `enabled`     TINYINT(1)    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dayz_server_mod_order` (`server_id`, `folder_name`),
    KEY `idx_dayz_server_mod_order_server` (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dayz_manager_settings` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `key`        VARCHAR(100)  NOT NULL,
    `value`      TEXT          NOT NULL DEFAULT '',
    `created_at` TIMESTAMP     NULL DEFAULT NULL,
    `updated_at` TIMESTAMP     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dayz_manager_settings_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Apply columns added in later migrations to any pre-existing installation.
-- The IF NOT EXISTS guard makes this safe to re-run on a fresh schema too.
ALTER TABLE `dayz_restart_schedules`
    ADD COLUMN IF NOT EXISTS `timed_restart_at`      TIMESTAMP     NULL DEFAULT NULL AFTER `next_restart_at`,
    ADD COLUMN IF NOT EXISTS `timed_warnings_sent`   VARCHAR(255)  NOT NULL DEFAULT '' AFTER `timed_restart_at`,
    ADD COLUMN IF NOT EXISTS `warning_minutes_enabled` VARCHAR(255) NOT NULL DEFAULT '180,120,60,30,20,10,5,2,1' AFTER `warnings_sent`,
    ADD COLUMN IF NOT EXISTS `warning_messages`      TEXT          NULL AFTER `warning_minutes_enabled`,
    ADD COLUMN IF NOT EXISTS `start_time`             VARCHAR(5)    NOT NULL DEFAULT '' AFTER `interval_minutes`,
    ADD COLUMN IF NOT EXISTS `last_restart_at`        TIMESTAMP     NULL DEFAULT NULL AFTER `next_restart_at`;

CREATE TABLE IF NOT EXISTS `dayz_dzsa_pending` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `server_id`    VARCHAR(64)   NOT NULL,
    `status`       VARCHAR(16)   NOT NULL DEFAULT 'waiting',
    `created_at`   TIMESTAMP     NULL DEFAULT NULL,
    `updated_at`   TIMESTAMP     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dayz_dzsa_pending_server` (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dayz_backups` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `server_id`   VARCHAR(64)   NOT NULL,
    `label`       VARCHAR(255)  NOT NULL DEFAULT '',
    `trigger`     VARCHAR(32)   NOT NULL DEFAULT 'manual',
    `payload`     LONGTEXT      NOT NULL,
    `created_at`  TIMESTAMP     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_dayz_backups_server` (`server_id`),
    KEY `idx_dayz_backups_created` (`server_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dayz_observed_players` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `server_id`       VARCHAR(64)   NOT NULL,
    `player_id`       VARCHAR(64)   NOT NULL,
    `player_name`     VARCHAR(255)  NOT NULL DEFAULT '',
    `last_map`        VARCHAR(64)   NOT NULL DEFAULT '',
    `last_x`          DECIMAL(10,2) NULL DEFAULT NULL,
    `last_y`          DECIMAL(10,2) NULL DEFAULT NULL,
    `last_z`          DECIMAL(10,2) NULL DEFAULT NULL,
    `last_direction`  DECIMAL(7,2)  NULL DEFAULT NULL,
    `last_alive`      TINYINT(1)    NOT NULL DEFAULT 1,
    `last_health`     DECIMAL(7,2)  NULL DEFAULT NULL,
    `inventory_json`  LONGTEXT      NULL,
    `metadata_json`   LONGTEXT      NULL,
    `first_seen_at`   TIMESTAMP     NULL DEFAULT NULL,
    `last_seen_at`    TIMESTAMP     NULL DEFAULT NULL,
    `created_at`      TIMESTAMP     NULL DEFAULT NULL,
    `updated_at`      TIMESTAMP     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dayz_observed_players_server_player` (`server_id`, `player_id`),
    KEY `idx_dayz_observed_players_server_seen` (`server_id`, `last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `dayz_observed_players`
    ADD COLUMN IF NOT EXISTS `steam64` VARCHAR(64) NULL DEFAULT NULL AFTER `player_id`,
    ADD KEY IF NOT EXISTS `idx_dayz_observed_players_steam64` (`steam64`);

CREATE TABLE IF NOT EXISTS `dayz_observed_player_backups` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `server_id`     VARCHAR(64)   NOT NULL,
    `player_id`     VARCHAR(64)   NOT NULL,
    `snapshot_json` LONGTEXT      NOT NULL,
    `created_at`    TIMESTAMP     NULL DEFAULT NULL,
    `updated_at`    TIMESTAMP     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_dayz_observed_player_backups_server_player` (`server_id`, `player_id`),
    KEY `idx_dayz_observed_player_backups_server_created` (`server_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
