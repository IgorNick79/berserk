-- Manual additive upgrade, MySQL 5.7 / MariaDB 10.8.
-- Apply ONCE only after checking that both columns and this index are absent.
-- Existing/partial schema: stop and inspect; do not blindly rerun.
-- Existing rooms keep NULL configuration and their original lifecycle.
-- No games/Engine data is updated. Deploy schema before enabling room setup UI.
ALTER TABLE lobby_rooms
    ADD COLUMN game_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN setup_json LONGTEXT NULL,
    ADD KEY idx_room_type_state_created (game_type,state,created_at);
