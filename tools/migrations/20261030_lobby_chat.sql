-- Apply manually in the lobby database after 20261030_lobby_wizard.sql (and the existing accounts schema).
-- Existing battle chat, games and room versions are untouched. Back up first.
-- Verify signed INT accounts.id/lobby_rooms.id and InnoDB before applying.
CREATE TABLE lobby_chat_messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 room_id INT NOT NULL,
 peer_account_id INT NOT NULL,
 sender_id INT NOT NULL,
 message VARCHAR(1000) NOT NULL,
 request_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME(6) NOT NULL,
 PRIMARY KEY (id),
 KEY ix_lobby_chat_page (room_id,peer_account_id,id),
 KEY ix_lobby_chat_rate (room_id,sender_id,created_at),
 UNIQUE KEY uq_lobby_chat_request (room_id,peer_account_id,sender_id,request_id),
 CONSTRAINT fk_lobby_chat_room FOREIGN KEY (room_id) REFERENCES lobby_rooms(id),
 CONSTRAINT fk_lobby_chat_peer FOREIGN KEY (peer_account_id) REFERENCES accounts(id),
 CONSTRAINT fk_lobby_chat_sender FOREIGN KEY (sender_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE lobby_chat_reads (
 room_id INT NOT NULL,
 peer_account_id INT NOT NULL,
 account_id INT NOT NULL,
 last_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY (room_id,peer_account_id,account_id),
 CONSTRAINT fk_lobby_read_room FOREIGN KEY (room_id) REFERENCES lobby_rooms(id),
 CONSTRAINT fk_lobby_read_peer FOREIGN KEY (peer_account_id) REFERENCES accounts(id),
 CONSTRAINT fk_lobby_read_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Runtime needs SELECT/INSERT on messages, SELECT/INSERT/UPDATE on reads,
-- and existing SELECT/UPDATE room privileges for transactional row locking.
-- Rollback code while retaining these tables; never drop message history automatically.
