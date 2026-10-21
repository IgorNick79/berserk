-- Manual additive Stage 5A migration. Apply once after preflight; no runtime DDL.
CREATE TABLE accounts (
    id INT NOT NULL AUTO_INCREMENT,
    login VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    login_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_accounts_login_key (login_key),
    CONSTRAINT chk_accounts_login CHECK (login REGEXP '^[A-Za-z0-9_-]{3,32}$'),
    CONSTRAINT chk_accounts_key CHECK (BINARY login_key = BINARY LOWER(login))
) ENGINE=InnoDB;
CREATE TABLE auth_login_attempts (
    bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_start DATETIME(6) NOT NULL,
    attempts INT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket_key),
    KEY idx_auth_window (window_start)
) ENGINE=InnoDB;
