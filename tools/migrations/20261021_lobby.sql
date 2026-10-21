-- Stage 5B, manual additive migration. Preflight signed INT IDs/InnoDB first.
CREATE TABLE lobby_rooms (
    id INT NOT NULL AUTO_INCREMENT,
    creator_account_id INT NOT NULL,
    candidate_account_id INT NULL,
    accepted_account_id INT NULL,
    candidate_request_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    game_id INT NULL,
    state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_room_creator (creator_account_id),
    KEY idx_room_candidate (candidate_account_id),
    KEY idx_room_accepted (accepted_account_id),
    KEY idx_room_state_updated (state,updated_at),
    UNIQUE KEY uq_room_game (game_id),
    CONSTRAINT fk_room_creator FOREIGN KEY (creator_account_id) REFERENCES accounts(id),
    CONSTRAINT fk_room_candidate FOREIGN KEY (candidate_account_id) REFERENCES accounts(id),
    CONSTRAINT fk_room_accepted FOREIGN KEY (accepted_account_id) REFERENCES accounts(id),
    CONSTRAINT fk_room_game FOREIGN KEY (game_id) REFERENCES games(ind),
    CONSTRAINT chk_room_state CHECK (
        (state='open' AND candidate_account_id IS NULL AND candidate_request_id IS NULL AND accepted_account_id IS NULL AND game_id IS NULL)
        OR (state='awaiting_confirmation' AND candidate_account_id IS NOT NULL AND candidate_account_id<>creator_account_id AND candidate_request_id IS NOT NULL AND accepted_account_id IS NULL AND game_id IS NULL)
        OR (state='started' AND candidate_account_id IS NULL AND candidate_request_id IS NULL AND accepted_account_id IS NOT NULL AND accepted_account_id<>creator_account_id AND game_id IS NOT NULL)
    ),
    CONSTRAINT chk_room_version CHECK (version > 0)
) ENGINE=InnoDB;
