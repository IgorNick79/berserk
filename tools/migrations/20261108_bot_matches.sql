-- Apply before configuring bot_account_id. Both lobby and gameplay use this room binding.
ALTER TABLE lobby_rooms
    ADD COLUMN bot_account_id INT NULL,
    ADD COLUMN bot_visited LONGTEXT NULL,
    ADD COLUMN bot_diagnostic VARCHAR(128) NULL;
