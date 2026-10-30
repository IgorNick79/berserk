-- Run ONCE in the lobby/game database after lobby_setup. Check SHOW CREATE TABLE first.
-- Existing rooms stay open; no game JSON or game versions are modified.
ALTER TABLE lobby_rooms
 ADD COLUMN invited_account_id INT NULL,
 ADD COLUMN creation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD UNIQUE KEY uq_lobby_creation (creator_account_id,creation_key),
 ADD KEY ix_lobby_invited (invited_account_id),
 ADD CONSTRAINT fk_lobby_invited FOREIGN KEY (invited_account_id) REFERENCES accounts(id);
