-- Stage 5D READ ONLY. Run in selected application schema; outputs no passwords/game JSON.
SELECT DATABASE() AS application_schema,CURRENT_USER(),VERSION(),@@sql_mode,@@default_storage_engine;
SHOW ENGINES;
SHOW GRANTS FOR CURRENT_USER;
SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
('games','accounts','auth_login_attempts','lobby_rooms','battle_command_journal','game_chat_messages','game_chat_reads','battle_history_matches','battle_history_batches','battle_history_actions','battle_history_events','battle_history_steps') ORDER BY TABLE_NAME;
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
('games','accounts','auth_login_attempts','lobby_rooms','battle_command_journal','game_chat_messages','game_chat_reads','battle_history_matches','battle_history_batches','battle_history_actions','battle_history_events','battle_history_steps') ORDER BY TABLE_NAME,ORDINAL_POSITION;
SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
('games','accounts','auth_login_attempts','lobby_rooms','battle_command_journal','game_chat_messages','game_chat_reads','battle_history_matches','battle_history_batches','battle_history_actions','battle_history_events','battle_history_steps') ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX;
SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME;
SELECT TABLE_NAME,COLUMN_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL;
SELECT CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE();
SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION
FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE();
-- Required existing games columns, one row per incompatibility. Empty result required.
SELECT expected.col AS missing_or_incompatible_games_column FROM (
 SELECT 'ind' col UNION ALL SELECT 'host_id' UNION ALL SELECT 'player_id' UNION ALL SELECT 'version'
) expected LEFT JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='games' AND c.COLUMN_NAME=expected.col
WHERE c.COLUMN_NAME IS NULL OR c.DATA_TYPE<>'int' OR c.COLUMN_TYPE LIKE '%unsigned%' OR c.IS_NULLABLE<>'NO';
-- Never auto-repair existing schemas. Compare complete metadata to reviewed migration DDL.
