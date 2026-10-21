-- Read only. Stop if tables already exist or games identifiers are incompatible.
SELECT DATABASE(), CURRENT_USER(), VERSION(), @@sql_mode;
SHOW CREATE TABLE games;
SHOW COLUMNS FROM games;
SHOW GRANTS FOR CURRENT_USER;
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('accounts','auth_login_attempts');
