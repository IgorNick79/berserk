-- Read only. Confirm database, signed INT IDs, InnoDB, games.version and grants.
SELECT DATABASE(),CURRENT_USER(),VERSION(),@@sql_mode;
SHOW CREATE TABLE accounts;
SHOW CREATE TABLE games;
SHOW INDEX FROM games;
SHOW GRANTS FOR CURRENT_USER;
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('accounts','games','lobby_rooms');
