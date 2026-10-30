-- AUTH database only. Run once, after 20261024_account_ui_preferences.sql.
-- Verify SHOW CREATE TABLE first. Stop on an already/partially applied migration.
-- No GameState rewrites. Existing panel_theme values remain intact.
ALTER TABLE account_ui_preferences
  ADD COLUMN playmat_id VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ADD COLUMN sound_enabled TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN revision BIGINT UNSIGNED NOT NULL DEFAULT 0;
-- A NULL mat is assigned once on first preference read using a conditional UPDATE.
-- Rollback only before rollout: drop these three columns (loses new preferences).
