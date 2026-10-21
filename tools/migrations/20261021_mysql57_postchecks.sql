-- MySQL 5.7 READ ONLY. Execute only after confirming these tables exist.
-- CHECK clauses are parsed but ignored; these counts must be zero before opening service.
SELECT @@foreign_key_checks,@@autocommit,@@tx_isolation,@@innodb_default_row_format;
SELECT COUNT(*) AS invalid_accounts FROM accounts
WHERE id<=0 OR login NOT REGEXP '^[A-Za-z0-9_-]{3,32}$' OR BINARY login_key<>BINARY LOWER(login);
-- Hash metadata only, never hashes/passwords. Review other prefixes/costs separately.
SELECT SUBSTRING(password_hash,1,7) AS hash_policy,COUNT(*) AS accounts_count
FROM accounts GROUP BY SUBSTRING(password_hash,1,7);
SELECT COUNT(*) AS invalid_rooms FROM lobby_rooms WHERE version=0 OR NOT (
(state='open' AND candidate_account_id IS NULL AND accepted_account_id IS NULL AND candidate_request_id IS NULL AND game_id IS NULL)
OR (state='awaiting_confirmation' AND candidate_account_id IS NOT NULL AND candidate_account_id<>creator_account_id AND accepted_account_id IS NULL AND candidate_request_id IS NOT NULL AND candidate_request_id REGEXP '^[0-9a-f]{64}$' AND game_id IS NULL)
OR (state='started' AND candidate_account_id IS NULL AND candidate_request_id IS NULL AND accepted_account_id IS NOT NULL AND accepted_account_id<>creator_account_id AND game_id IS NOT NULL));
SELECT COUNT(*) AS inconsistent_started_ownership FROM lobby_rooms r LEFT JOIN games g ON g.ind=r.game_id
WHERE r.state='started' AND (g.ind IS NULL OR NOT (g.host_id <=> r.creator_account_id) OR NOT (g.player_id <=> r.accepted_account_id));
SELECT TABLE_NAME,CONSTRAINT_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA=DATABASE();
-- Absence is expected on MySQL 5.7; do not execute SELECT @@in_transaction.
SHOW VARIABLES LIKE 'in_transaction';
