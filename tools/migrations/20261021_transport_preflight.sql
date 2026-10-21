-- READ ONLY. Existing schema must be ready BEFORE activating Stage 5C.
SELECT DATABASE(),CURRENT_USER(),VERSION(),@@sql_mode;
SHOW CREATE TABLE accounts;
SHOW CREATE TABLE lobby_rooms;
SHOW CREATE TABLE games;
SHOW CREATE TABLE battle_command_journal;
SHOW CREATE TABLE game_chat_messages;
SHOW CREATE TABLE game_chat_reads;
SHOW INDEX FROM battle_command_journal;
SHOW GRANTS FOR CURRENT_USER;
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
('games','lobby_rooms','battle_command_journal','game_chat_messages','game_chat_reads',
 'battle_history_matches','battle_history_batches','battle_history_actions','battle_history_events','battle_history_steps');
