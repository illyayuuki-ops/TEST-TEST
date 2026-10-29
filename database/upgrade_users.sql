-- Run in phpMyAdmin (select your database first -> SQL tab) if registration fails on a database
-- that was imported from an OLDER version of ticketdesk.sql. Safe to run more than once (MariaDB / MySQL 8+).
ALTER TABLE users ADD COLUMN IF NOT EXISTS school_id VARCHAR(40) NOT NULL DEFAULT '' AFTER full_name;
ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS failed_attempts INT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL;
ALTER TABLE users MODIFY role ENUM('super_admin','admin','student','alumni') NOT NULL;
