-- Run once in the AUTH database (the same database as accounts).
-- Additive only: no match JSON, gameplay tables or account credentials are modified.
CREATE TABLE account_ui_preferences (
    account_id INT NOT NULL,
    panel_theme VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'grimoire',
    PRIMARY KEY (account_id),
    CONSTRAINT fk_ui_preferences_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;
