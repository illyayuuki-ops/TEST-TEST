-- Run ONCE in phpMyAdmin (select your database -> SQL tab) to add student email confirmation + alumni card approval.
-- Existing accounts stay 'verified', so nobody already registered is locked out. Safe to re-run (MariaDB / MySQL 8+).
ALTER TABLE users ADD COLUMN IF NOT EXISTS verification   ENUM('verified','email_pending','card_pending') NOT NULL DEFAULT 'verified';
ALTER TABLE users ADD COLUMN IF NOT EXISTS verify_token   CHAR(64)     NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS verify_expires DATETIME     NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS card_path      VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS reviewed_by    INT          NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS reviewed_at    DATETIME     NULL;
