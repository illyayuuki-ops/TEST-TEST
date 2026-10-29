-- TicketDesk upgrade: manual QR codes + settings
-- Run ONCE in phpMyAdmin (SQL tab) on a database that already has the categories upgrade.

CREATE TABLE IF NOT EXISTS app_settings (
  k VARCHAR(60)  NOT NULL PRIMARY KEY,
  v VARCHAR(500) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS qr_codes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(80)  NOT NULL,
  subtitle    VARCHAR(140) NOT NULL DEFAULT '',
  target      ENUM('office','student_login','student_signup','custom') NOT NULL,
  office_code VARCHAR(30)  NULL,
  custom_url  VARCHAR(500) NOT NULL DEFAULT '',
  color       VARCHAR(7)   NOT NULL DEFAULT '#00d4ff',
  created_by  INT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_qr_office FOREIGN KEY (office_code) REFERENCES offices(office_code) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

