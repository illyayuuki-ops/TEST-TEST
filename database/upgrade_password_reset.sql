-- Forgot-password support (students/alumni get a one-hour emailed reset link). Run ONCE in phpMyAdmin; safe to re-run (MariaDB).
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token   CHAR(64) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_expires DATETIME NULL;
