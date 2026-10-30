CREATE TABLE IF NOT EXISTS gd_plus_students (
 user_id INT PRIMARY KEY, program VARCHAR(120) NOT NULL, year_level VARCHAR(40) NOT NULL, section VARCHAR(80) NOT NULL,
 source VARCHAR(200) NOT NULL, verified_by INT NOT NULL, updated_at DATETIME NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT, FOREIGN KEY(verified_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_plus_services (
 category_id INT PRIMARY KEY, description TEXT NOT NULL, parent_id INT NULL, counselor_id INT NULL,
 low_hours INT NOT NULL DEFAULT 120, normal_hours INT NOT NULL DEFAULT 72, high_hours INT NOT NULL DEFAULT 48, urgent_hours INT NOT NULL DEFAULT 24,
 FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE RESTRICT, FOREIGN KEY(parent_id) REFERENCES categories(id) ON DELETE RESTRICT,
 FOREIGN KEY(counselor_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_plus_cases (
 ticket_id INT PRIMARY KEY, priority_override VARCHAR(20) NULL, preferred_counselor INT NULL, preferred_slot DATETIME NULL,
 review_status VARCHAR(30) NOT NULL DEFAULT 'pending', updated_at DATETIME NOT NULL,
 FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE RESTRICT, FOREIGN KEY(preferred_counselor) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_plus_case_log (
 id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, actor_id INT NOT NULL, action VARCHAR(40) NOT NULL, reason TEXT NOT NULL, created_at DATETIME NOT NULL,
 FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE RESTRICT, FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE RESTRICT, INDEX(ticket_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_plus_events (
 event_id INT PRIMARY KEY, facilitator VARCHAR(160) NOT NULL DEFAULT '', late_minutes INT NULL, manual_window TINYINT NOT NULL DEFAULT 0,
 FOREIGN KEY(event_id) REFERENCES gd_events(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_plus_attendance (
 invite_id INT PRIMARY KEY, method VARCHAR(30) NOT NULL, checked_by INT NOT NULL, updated_at DATETIME NOT NULL,
 FOREIGN KEY(invite_id) REFERENCES gd_invites(id) ON DELETE RESTRICT, FOREIGN KEY(checked_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_plus_waitlist (
 invite_id INT PRIMARY KEY, state VARCHAR(20) NOT NULL, queued_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 FOREIGN KEY(invite_id) REFERENCES gd_invites(id) ON DELETE RESTRICT, INDEX(state,queued_at,invite_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_plus_notices (
 notification_id INT PRIMARY KEY, view_name VARCHAR(30) NOT NULL, record_id INT NOT NULL DEFAULT 0,
 FOREIGN KEY(notification_id) REFERENCES notifications(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_plus_privacy (
 privacy_id INT PRIMARY KEY, stage VARCHAR(30) NOT NULL, updated_by INT NOT NULL, updated_at DATETIME NOT NULL,
 FOREIGN KEY(privacy_id) REFERENCES gd_privacy(id) ON DELETE RESTRICT, FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS gd_plus_privacy_log (
 id INT AUTO_INCREMENT PRIMARY KEY, privacy_id INT NOT NULL, stage VARCHAR(30) NOT NULL, response TEXT NOT NULL, actor_id INT NOT NULL, created_at DATETIME NOT NULL,
 FOREIGN KEY(privacy_id) REFERENCES gd_privacy(id) ON DELETE RESTRICT, FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
