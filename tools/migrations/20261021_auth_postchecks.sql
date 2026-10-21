-- Read only. Verify types, CHECK constraints, InnoDB and unique/primary indexes.
SHOW CREATE TABLE accounts;
SHOW CREATE TABLE auth_login_attempts;
SHOW INDEX FROM accounts;
SHOW INDEX FROM auth_login_attempts;
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('accounts','auth_login_attempts');
SELECT COUNT(*) AS invalid_accounts FROM accounts
WHERE id <= 0 OR login NOT REGEXP '^[A-Za-z0-9_-]{3,32}$' OR BINARY login_key <> BINARY LOWER(login);
