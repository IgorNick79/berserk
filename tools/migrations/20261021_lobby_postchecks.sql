-- Read only; do not select games.json or password hashes for published evidence.
SHOW CREATE TABLE lobby_rooms;
SHOW INDEX FROM lobby_rooms;
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lobby_rooms';
SELECT COUNT(*) AS invalid_rooms FROM lobby_rooms
WHERE version=0 OR NOT (
 (state='open' AND candidate_account_id IS NULL AND candidate_request_id IS NULL AND accepted_account_id IS NULL AND game_id IS NULL)
 OR (state='awaiting_confirmation' AND candidate_account_id IS NOT NULL AND candidate_account_id<>creator_account_id AND candidate_request_id IS NOT NULL AND accepted_account_id IS NULL AND game_id IS NULL)
 OR (state='started' AND candidate_account_id IS NULL AND candidate_request_id IS NULL AND accepted_account_id IS NOT NULL AND accepted_account_id<>creator_account_id AND game_id IS NOT NULL)
);
