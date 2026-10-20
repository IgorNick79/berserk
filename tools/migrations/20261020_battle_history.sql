-- Stage 4A.1: fresh installations only; no triggers or TRIGGER privilege required.
-- Existing production tables: do NOT rerun. Use read-only schema postchecks instead.
-- No external FKs: production games/journal DDL must be verified separately.
-- BIGINT signed game_id accepts signed/unsigned INT game IDs without guessing games.ind.
CREATE TABLE battle_history_matches (
 game_id BIGINT NOT NULL,
 recording_status VARCHAR(16) NOT NULL,
 completeness VARCHAR(16) NOT NULL,
 event_version SMALLINT UNSIGNED NOT NULL,
 snapshot_version SMALLINT UNSIGNED NOT NULL,
 has_initial TINYINT UNSIGNED NOT NULL DEFAULT 0,
 has_final TINYINT UNSIGNED NOT NULL DEFAULT 0,
 last_event_seq BIGINT UNSIGNED NOT NULL DEFAULT 0,
 last_step_seq BIGINT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME(6) NOT NULL,
 updated_at DATETIME(6) NOT NULL,
 PRIMARY KEY (game_id),
 CHECK (recording_status IN ('disabled','recording','completed')),
 CHECK (completeness IN ('none','complete','incomplete')),
 CHECK (has_initial IN (0,1) AND has_final IN (0,1)),
 CHECK (event_version > 0 AND snapshot_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE battle_history_batches (
 game_id BIGINT NOT NULL,
 journal_id BIGINT UNSIGNED NOT NULL,
 player_key VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 command_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 persistence_version INT NOT NULL,
 batch_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 first_event_seq BIGINT UNSIGNED NULL,
 last_event_seq BIGINT UNSIGNED NULL,
 first_step_seq BIGINT UNSIGNED NULL,
 last_step_seq BIGINT UNSIGNED NULL,
 created_at DATETIME(6) NOT NULL,
 PRIMARY KEY (game_id,journal_id),
 UNIQUE KEY uq_history_journal (journal_id),
 UNIQUE KEY uq_history_command (game_id,player_key,command_id),
 KEY idx_history_command_id (game_id,command_id),
 FOREIGN KEY (game_id) REFERENCES battle_history_matches(game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE battle_history_actions (
 game_id BIGINT NOT NULL,
 action_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 root_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 parent_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 action_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 initiator VARCHAR(16) NOT NULL,
 lifecycle VARCHAR(16) NOT NULL,
 started_event_seq BIGINT UNSIGNED NOT NULL,
 ended_event_seq BIGINT UNSIGNED NULL,
 completion_json LONGTEXT NULL,
 PRIMARY KEY (game_id,action_id),
 KEY idx_history_root (game_id,root_id),
 FOREIGN KEY (game_id) REFERENCES battle_history_matches(game_id),
 CHECK (lifecycle IN ('open','settled','cancelled','interrupted')),
 CHECK (completion_json IS NULL OR JSON_VALID(completion_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE battle_history_events (
 game_id BIGINT NOT NULL,
 event_seq BIGINT UNSIGNED NOT NULL,
 journal_id BIGINT UNSIGNED NOT NULL,
 command_order INT UNSIGNED NOT NULL,
 action_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 schema_version SMALLINT UNSIGNED NOT NULL,
 cause_event_seq BIGINT UNSIGNED NULL,
 payload_json LONGTEXT NOT NULL,
 public_json LONGTEXT NULL,
 host_json LONGTEXT NULL,
 player_json LONGTEXT NULL,
 occurred_at DATETIME(6) NOT NULL,
 PRIMARY KEY (game_id,event_seq),
 UNIQUE KEY uq_history_event_order (game_id,journal_id,command_order),
 KEY idx_history_event_action (game_id,action_id,event_seq),
 FOREIGN KEY (game_id,journal_id) REFERENCES battle_history_batches(game_id,journal_id),
 FOREIGN KEY (game_id,action_id) REFERENCES battle_history_actions(game_id,action_id),
 CHECK (schema_version > 0),
 CHECK (JSON_VALID(payload_json)),
 CHECK (public_json IS NULL OR JSON_VALID(public_json)),
 CHECK (host_json IS NULL OR JSON_VALID(host_json)),
 CHECK (player_json IS NULL OR JSON_VALID(player_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE battle_history_steps (
 game_id BIGINT NOT NULL,
 step_seq BIGINT UNSIGNED NOT NULL,
 journal_id BIGINT UNSIGNED NOT NULL,
 capture_order INT UNSIGNED NOT NULL,
 boundary_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 step_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 action_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 last_event_seq BIGINT UNSIGNED NOT NULL,
 persistence_version INT NOT NULL,
 schema_version SMALLINT UNSIGNED NOT NULL,
 state_json LONGTEXT NOT NULL,
 public_json LONGTEXT NOT NULL,
 host_json LONGTEXT NOT NULL,
 player_json LONGTEXT NOT NULL,
 state_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 is_initial TINYINT UNSIGNED NOT NULL,
 is_final TINYINT UNSIGNED NOT NULL,
 initial_guard TINYINT GENERATED ALWAYS AS (CASE WHEN is_initial=1 THEN 1 ELSE NULL END) STORED,
 final_guard TINYINT GENERATED ALWAYS AS (CASE WHEN is_final=1 THEN 1 ELSE NULL END) STORED,
 created_at DATETIME(6) NOT NULL,
 PRIMARY KEY (game_id,step_seq),
 UNIQUE KEY uq_history_boundary (game_id,boundary_key),
 UNIQUE KEY uq_history_capture (game_id,journal_id,capture_order),
 UNIQUE KEY uq_history_initial (game_id,initial_guard),
 UNIQUE KEY uq_history_final (game_id,final_guard),
 FOREIGN KEY (game_id,journal_id) REFERENCES battle_history_batches(game_id,journal_id),
 FOREIGN KEY (game_id,action_id) REFERENCES battle_history_actions(game_id,action_id),
 CHECK (is_initial IN (0,1) AND is_final IN (0,1)),
 CHECK (schema_version > 0),
 CHECK (JSON_VALID(state_json) AND JSON_VALID(public_json) AND JSON_VALID(host_json) AND JSON_VALID(player_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
