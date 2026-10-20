-- Stage 4A.1: READ ONLY. Select the intended database in phpMyAdmin first.
-- Five production tables already exist. Do NOT rerun the CREATE migration.
SELECT DATABASE() AS selected_database, CURRENT_USER() AS sql_account,
 VERSION() AS server_version, @@sql_mode AS sql_mode;
SHOW CREATE TABLE games;
SHOW CREATE TABLE battle_command_journal;
SHOW CREATE TABLE battle_history_matches;
SHOW CREATE TABLE battle_history_batches;
SHOW CREATE TABLE battle_history_actions;
SHOW CREATE TABLE battle_history_events;
SHOW CREATE TABLE battle_history_steps;
SHOW INDEX FROM battle_history_matches;
SHOW INDEX FROM battle_history_batches;
SHOW INDEX FROM battle_history_actions;
SHOW INDEX FROM battle_history_events;
SHOW INDEX FROM battle_history_steps;
SHOW TABLE STATUS WHERE Name IN ('games','battle_command_journal',
 'battle_history_matches','battle_history_batches','battle_history_actions',
 'battle_history_events','battle_history_steps');
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,
 CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('games','battle_command_journal',
 'battle_history_matches','battle_history_batches','battle_history_actions',
 'battle_history_events','battle_history_steps')
ORDER BY TABLE_NAME,ORDINAL_POSITION;
SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE
FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('battle_history_matches',
 'battle_history_batches','battle_history_actions','battle_history_events','battle_history_steps')
ORDER BY TABLE_NAME,CONSTRAINT_TYPE,CONSTRAINT_NAME;
SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,
 REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('battle_history_matches',
 'battle_history_batches','battle_history_actions','battle_history_events','battle_history_steps')
ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION;
-- CHECK expressions are authoritative in SHOW CREATE TABLE above.
-- Missing TRIGGER privilege can hide trigger metadata: an empty list alone does not prove absence.
SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN ('battle_history_matches',
 'battle_history_batches','battle_history_actions','battle_history_events','battle_history_steps');
SELECT 'matches' AS entity,COUNT(*) AS rows_count FROM battle_history_matches
UNION ALL SELECT 'batches',COUNT(*) FROM battle_history_batches
UNION ALL SELECT 'actions',COUNT(*) FROM battle_history_actions
UNION ALL SELECT 'events',COUNT(*) FROM battle_history_events
UNION ALL SELECT 'steps',COUNT(*) FROM battle_history_steps;
