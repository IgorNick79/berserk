-- Explicit migration only. Back up the database first. No game rows are modified.
-- Verify SHOW COLUMNS FROM games (ind, host_id, player_id) are signed INT before applying.
-- No cascading foreign keys: chat history must survive game completion.
CREATE TABLE game_chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id INT NOT NULL,
    sender_id INT NOT NULL,
    turn_number INT UNSIGNED NOT NULL,
    message VARCHAR(1000) NOT NULL,
    request_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_chat_game_id (game_id, id),
    KEY idx_chat_sender_time (game_id, sender_id, created_at),
    UNIQUE KEY uq_chat_request (game_id, sender_id, request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE game_chat_reads (
    game_id INT NOT NULL,
    player_id INT NOT NULL,
    last_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (game_id, player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
