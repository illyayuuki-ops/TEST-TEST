-- Profile photo for students/alumni (safe to re-run). phpMyAdmin -> select your database -> SQL tab -> run this file.
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(80) NULL;
