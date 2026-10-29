-- Additive extension of TicketDesk 75fe13e. No duplicate users, tickets, replies or attachments.
CREATE TABLE IF NOT EXISTS gd_staff (
 user_id INT PRIMARY KEY, guidance_role ENUM('head','counselor','administrator') NOT NULL, active TINYINT NOT NULL DEFAULT 1,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_sessions (
 token_hash CHAR(64) PRIMARY KEY, user_id INT NOT NULL, expires_at DATETIME NOT NULL, INDEX(user_id),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_case_meta (
 ticket_id INT PRIMARY KEY, created_by INT NULL,
 FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE RESTRICT, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_preferences (
 user_id INT PRIMARY KEY, ticket_updates TINYINT NOT NULL DEFAULT 1,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_feedback (
 ticket_id INT PRIMARY KEY, rating TINYINT NOT NULL, feedback TEXT NOT NULL, updated_at DATETIME NOT NULL,
 FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_programs (
 id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(160) NOT NULL, description TEXT NOT NULL, active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_events (
 id INT AUTO_INCREMENT PRIMARY KEY, program_id INT NULL, session_no INT NOT NULL DEFAULT 1,
 title VARCHAR(160) NOT NULL, description TEXT NOT NULL, kind VARCHAR(40) NOT NULL,
 status ENUM('draft','published','completed','cancelled') NOT NULL DEFAULT 'draft',
 starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, venue VARCHAR(160) NOT NULL, capacity INT NOT NULL,
 certificates_enabled TINYINT NOT NULL DEFAULT 0, credit_units DECIMAL(8,2) NOT NULL DEFAULT 0,
 checkin_code CHAR(16) NOT NULL, minutes_before INT NOT NULL DEFAULT 30, minutes_after INT NOT NULL DEFAULT 60,
 created_by INT NOT NULL, updated_at DATETIME NOT NULL,
 FOREIGN KEY(program_id) REFERENCES gd_programs(id) ON DELETE RESTRICT, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_invites (
 id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, user_id INT NOT NULL,
 state ENUM('invited','registered','declined') NOT NULL DEFAULT 'invited',
 attendance ENUM('pending','present','late','absent','excused') NOT NULL DEFAULT 'pending', pass_code CHAR(48) NOT NULL UNIQUE,
 checked_at DATETIME NULL, UNIQUE(event_id,user_id),
 FOREIGN KEY(event_id) REFERENCES gd_events(id) ON DELETE RESTRICT, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_certificates (
 id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, user_id INT NOT NULL, verify_code CHAR(48) NOT NULL UNIQUE,
 recipient_name VARCHAR(120) NOT NULL, event_title VARCHAR(160) NOT NULL, event_date DATETIME NOT NULL,
 credits DECIMAL(8,2) NOT NULL, issued_by INT NOT NULL, issued_at DATETIME NOT NULL, UNIQUE(event_id,user_id),
 FOREIGN KEY(event_id) REFERENCES gd_events(id) ON DELETE RESTRICT, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(issued_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_appointments (
 id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, counselor_id INT NOT NULL,
 starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, concern TEXT NOT NULL,
 status ENUM('requested','confirmed','completed','cancelled') NOT NULL DEFAULT 'requested', updated_at DATETIME NOT NULL,
 INDEX(counselor_id,starts_at), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT, FOREIGN KEY(counselor_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_articles (
 id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(160) NOT NULL, body TEXT NOT NULL, published TINYINT NOT NULL DEFAULT 0,
 updated_by INT NOT NULL, updated_at DATETIME NOT NULL, FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_privacy (
 id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, reason TEXT NOT NULL, status ENUM('submitted','reviewed') NOT NULL DEFAULT 'submitted',
 response TEXT NULL, reviewed_by INT NULL, updated_at DATETIME NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT, FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_rate (
 bucket CHAR(64) PRIMARY KEY, attempts INT NOT NULL, expires_at DATETIME NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_settings (
 setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NOT NULL
) ENGINE=InnoDB;
