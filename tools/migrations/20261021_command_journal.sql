-- Existing journal schema, manual installation only if absent. No runtime DDL.
CREATE TABLE battle_command_journal (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                game_id INT NOT NULL,
                player_key VARCHAR(16) NOT NULL,
                command_id VARCHAR(64) NOT NULL,
                command_type VARCHAR(64) NOT NULL,
                payload_hash CHAR(64) NOT NULL,
                expected_version INT NOT NULL,
                before_version INT NULL,
                after_version INT NULL,
                status VARCHAR(16) NOT NULL,
                error_code VARCHAR(64) NULL,
                error_message TEXT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                finished_at DATETIME(6) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_battle_command (game_id, player_key, command_id),
                KEY idx_battle_command_status (game_id, status, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
