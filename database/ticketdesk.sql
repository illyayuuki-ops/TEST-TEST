-- TicketDesk: multi-office ticketing (Super Admin > Office Admin > Student/Alumni)
-- NOTE: if you already have a database named `ticketdesk` from an older version, drop it first
--       (DROP DATABASE ticketdesk;) — this script uses CREATE TABLE IF NOT EXISTS and won't overwrite old tables.
CREATE DATABASE IF NOT EXISTS ticketdesk CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE ticketdesk;

-- Offices / departments (equivalent of "businesses" in comprowg)
CREATE TABLE IF NOT EXISTS offices (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  office_code  VARCHAR(30)  NOT NULL UNIQUE,
  office_name  VARCHAR(100) NOT NULL,
  description  VARCHAR(255) NOT NULL DEFAULT '',
  contact      VARCHAR(100) NOT NULL DEFAULT '',
  brand_color  VARCHAR(7)   NOT NULL DEFAULT '#5E94CF',
  brand_icon   VARCHAR(40)  NOT NULL DEFAULT 'fa-building',
  status       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- role: super_admin (no office) | admin (one office) | student / alumni (no office; pick one at login)
CREATE TABLE IF NOT EXISTS users (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(50)  NOT NULL UNIQUE,
  email                VARCHAR(120) NOT NULL UNIQUE,
  full_name            VARCHAR(120) NOT NULL,
  school_id            VARCHAR(40)  NOT NULL DEFAULT '',
  password             VARCHAR(255) NOT NULL,
  role                 ENUM('super_admin','admin','student','alumni') NOT NULL,
  office_code          VARCHAR(30)  NULL,
  status               ENUM('active','inactive') NOT NULL DEFAULT 'active',
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  failed_attempts      INT          NOT NULL DEFAULT 0,
  locked_until         DATETIME     NULL,
  verification         ENUM('verified','email_pending','card_pending') NOT NULL DEFAULT 'verified',
  verify_token         CHAR(64)     NULL,
  verify_expires       DATETIME     NULL,
  card_path            VARCHAR(100) NULL,
  reset_token          CHAR(64)     NULL,
  reset_expires        DATETIME     NULL,
  reviewed_by          INT          NULL,
  reviewed_at          DATETIME     NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_office FOREIGN KEY (office_code) REFERENCES offices(office_code) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Categories are per office. New offices get the generic set; 'Other' is locked (fallback when a category is deleted).
-- tickets.category_id has no FK on purpose: deleting an office must still cascade cleanly; the app moves tickets to 'Other' before deleting a category.
CREATE TABLE IF NOT EXISTS categories (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  office_code VARCHAR(30) NOT NULL,
  name        VARCHAR(60) NOT NULL,
  is_hidden   TINYINT(1)  NOT NULL DEFAULT 0,
  is_locked   TINYINT(1)  NOT NULL DEFAULT 0,
  sort_order  INT         NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_office_category (office_code, name),
  CONSTRAINT fk_cat_office FOREIGN KEY (office_code) REFERENCES offices(office_code) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Each ticket belongs to exactly one office -> only that office's admins can manage it
CREATE TABLE IF NOT EXISTS tickets (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(30) NULL UNIQUE,
  office_code VARCHAR(30) NOT NULL,
  user_id     INT NOT NULL,
  category_id INT NOT NULL,
  subject     VARCHAR(150) NOT NULL,
  details     TEXT NOT NULL,
  priority    ENUM('Low','Normal','Urgent') NOT NULL DEFAULT 'Normal',
  status      ENUM('Open','Reopened','In Progress','Waiting for User','Escalated','Resolved','Closed','Cancelled') NOT NULL DEFAULT 'Open',
  assigned_to INT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  INDEX idx_office_status (office_code, status),
  INDEX idx_assigned (assigned_to),
  INDEX idx_category (category_id),
  CONSTRAINT fk_ticket_office FOREIGN KEY (office_code) REFERENCES offices(office_code) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ticket_user   FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS replies (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id  INT NOT NULL,
  user_id    INT NOT NULL,
  message    TEXT NOT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reply_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_reply_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- Settings + manually created QR codes (superadmin → QR Codes). Deleting an office removes its QR codes.
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

-- Same idea as comprowg audit_logs (no FKs, so history survives deletions)
CREATE TABLE IF NOT EXISTS audit_logs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NULL,
  username    VARCHAR(100) NULL,
  role        VARCHAR(30)  NULL,
  office_code VARCHAR(30)  NULL,
  action      VARCHAR(100) NOT NULL,
  description TEXT NULL,
  ip_address  VARCHAR(45)  NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at), INDEX idx_action (action), INDEX idx_office_created (office_code, created_at)
) ENGINE=InnoDB;

-- ── Seed data ───────────────────────────────────────────────────────────────
-- Default logins (all force a password change on first sign-in):
--   SAO admin   : sao_admin / sao_admin123
INSERT INTO offices (office_code, office_name, description, contact, brand_color, brand_icon) VALUES
 ('sao','Student Affairs Office','IDs, discipline, org permits, lost & found','sao@school.edu','#5E94CF','fa-user-graduate'),
 ('registrar','Registrar','Records, enrollment and document requests','registrar@school.edu','#7B6BC4','fa-file-lines'),
 ('library','Library','Borrowing, fines and research help','library@school.edu','#2F9E6F','fa-book');

INSERT INTO users (username,email,full_name,password,role,office_code,must_change_password) VALUES
 ('sao_admin','sao_admin@school.edu','SAO Admin','$2y$10$I3nfDxnZcGaSJSb3EcmhGeNh6PxC8VkHDTF5.UqLzYYIVedkdftqi','admin','sao',1);

-- Default categories: SAO keeps its real Student Affairs list; the other offices get theirs from the department presets at the bottom of this file.
INSERT INTO categories (office_code, name, is_locked, sort_order) VALUES
 ('sao','ID / Records Concern',0,10),('sao','Scholarship / Financial Aid',0,20),('sao','Discipline / Conduct',0,30),
 ('sao','Organization / Event Permit',0,40),('sao','Facilities / Lost & Found',0,50),('sao','Other',1,60);

-- ── Department presets (checklist section 3) ───────────────────────────────
-- Department presets from the checklist (Section 3: Minimum Department Coverage).
-- Adds any missing office and its sample ticket categories. Existing offices/categories are never changed or removed (INSERT IGNORE).
INSERT IGNORE INTO offices (office_code, office_name, description, contact, brand_color, brand_icon) VALUES
 ('registrar','Registrar','Records, enrollment, transcripts and document requests','','#7B6BC4','fa-file-lines'),
 ('finance','Finance','Payments, assessments, refunds and billing','','#2F9E6F','fa-money-bill-wave'),
 ('hr','Human Resources','Employment records, payroll, leave and benefits','','#C26A4A','fa-briefcase'),
 ('itdc','ITDC','Network, accounts, software and computer lab support','','#2A8FA8','fa-laptop-code'),
 ('sao','Student Affairs Office','IDs, discipline, org permits, student welfare and campus events','','#5E94CF','fa-user-graduate'),
 ('admission','Admission & Scholarships','Admission, applications, scholarships and financial assistance','','#B8860B','fa-user-graduate'),
 ('main','Maintenance Office','Repairs, utilities and facilities requests','','#8A6D3B','fa-screwdriver-wrench'),
 ('library','Library','Borrowing, database access, overdue notices and research help','','#2F9E6F','fa-book'),
 ('marketing','Marketing','Publications, promotions, social media and branding','','#D1477A','fa-bullhorn'),
 ('aea','External Affairs & Alumni','Alumni records, partnerships, MOAs and alumni events','','#7B6BC4','fa-award'),
 ('guide','Guidance Office','Counseling, career guidance and referrals','','#3E9B9B','fa-people-group'),
 ('acads','Academic Office','Curriculum, scheduling, academic policies (Basic Ed & College)','','#4A6FA5','fa-school');

INSERT IGNORE INTO categories (office_code, name, is_locked, sort_order) VALUES
 ('registrar','Enrollment Records',0,110),
 ('registrar','Transcript Request',0,120),
 ('registrar','Diploma Request',0,130),
 ('registrar','Transfer Credentials',0,140),
 ('registrar','Correction of Records',0,150),
 ('finance','Payment Concern',0,110),
 ('finance','Assessment Query',0,120),
 ('finance','Refund',0,130),
 ('finance','Billing Dispute',0,140),
 ('finance','Scholarship Billing',0,150),
 ('hr','Employment Records',0,110),
 ('hr','Payroll Concern',0,120),
 ('hr','Leave Application',0,130),
 ('hr','Benefits',0,140),
 ('hr','Faculty / Staff Request',0,150),
 ('itdc','Network Access',0,110),
 ('itdc','Wi-Fi Concern',0,120),
 ('itdc','Computer Lab & Office Support',0,130),
 ('itdc','Software Installation',0,140),
 ('itdc','Email & Account Access',0,150),
 ('sao','Student Activities',0,110),
 ('sao','Organization Request',0,120),
 ('sao','Disciplinary Concern',0,130),
 ('sao','Student Welfare',0,140),
 ('sao','Campus Events',0,150),
 ('admission','Admission Requirements',0,110),
 ('admission','Application Status',0,120),
 ('admission','Scholarship Application',0,130),
 ('admission','Scholarship Renewal',0,140),
 ('admission','Financial Assistance',0,150),
 ('main','Classroom Repair',0,110),
 ('main','Electrical Concern',0,120),
 ('main','Plumbing',0,130),
 ('main','Air-Conditioning',0,140),
 ('main','Furniture',0,150),
 ('main','Facilities Request',0,160),
 ('library','Book Reservation',0,110),
 ('library','Library Card Concern',0,120),
 ('library','Database Access',0,130),
 ('library','Overdue Notice',0,140),
 ('library','Research Assistance',0,150),
 ('marketing','Publication Request',0,110),
 ('marketing','Event Promotion',0,120),
 ('marketing','Social Media Content',0,130),
 ('marketing','Branding Materials',0,140),
 ('marketing','Media Inquiry',0,150),
 ('aea','Alumni Records',0,110),
 ('aea','Partnerships',0,120),
 ('aea','Memorandum of Agreement',0,130),
 ('aea','Alumni Events',0,140),
 ('aea','External Linkages',0,150),
 ('guide','Counseling Appointment',0,110),
 ('guide','Career Guidance',0,120),
 ('guide','Psychological Services',0,130),
 ('guide','Student Concern',0,140),
 ('guide','Referral Request',0,150),
 ('acads','Curriculum Concern',0,110),
 ('acads','Class Scheduling',0,120),
 ('acads','Academic Policy',0,130),
 ('acads','Faculty Concern',0,140),
 ('acads','Basic Education Request',0,150),
 ('acads','College Academic Request',0,160);

-- every office keeps its locked 'Other' fallback
INSERT IGNORE INTO categories (office_code, name, is_locked, sort_order) SELECT office_code, 'Other', 0, 999 FROM offices;

-- ticket file attachments
CREATE TABLE IF NOT EXISTS ticket_files (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id     INT NOT NULL,
  reply_id      INT NULL,
  user_id       INT NOT NULL,
  original_name VARCHAR(150) NOT NULL,
  stored_name   VARCHAR(80)  NOT NULL,
  mime          VARCHAR(100) NOT NULL,
  size_bytes    INT NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_file_ticket (ticket_id),
  CONSTRAINT fk_file_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_reply  FOREIGN KEY (reply_id)  REFERENCES replies(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- profile photo
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(80) NULL;
