<?php

declare(strict_types=1);

return [
    'up' => [
        'CREATE TABLE IF NOT EXISTS game_panel_mod_states (slug VARCHAR(191) PRIMARY KEY, installed TINYINT(1) NOT NULL, enabled TINYINT(1) NOT NULL, version VARCHAR(64) NOT NULL, updated_at TIMESTAMP NULL);',
    ],
    'down' => [
        'DROP TABLE IF EXISTS game_panel_mod_states;',
    ],
];
