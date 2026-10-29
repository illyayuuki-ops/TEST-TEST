-- TicketDesk upgrade: per-office categories
-- Run ONCE on a database that was created from the OLD ticketdesk.sql (tickets.category was plain text).
-- phpMyAdmin: select your database, open the SQL tab, paste this whole file, Go.
-- Back up your database first (Export tab).

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

-- 1) SAO keeps its real Student Affairs categories
INSERT IGNORE INTO categories (office_code, name, is_locked, sort_order)
SELECT o.office_code, v.name, 0, v.so FROM offices o JOIN (
  SELECT 'ID / Records Concern' name, 10 so UNION ALL SELECT 'Scholarship / Financial Aid', 20 UNION ALL SELECT 'Discipline / Conduct', 30
  UNION ALL SELECT 'Organization / Event Permit', 40 UNION ALL SELECT 'Facilities / Lost & Found', 50) v
WHERE o.office_code = 'sao';

-- 2) Every other office gets the generic set
INSERT IGNORE INTO categories (office_code, name, is_locked, sort_order)
SELECT o.office_code, v.name, 0, v.so FROM offices o JOIN (
  SELECT 'General Inquiry' name, 10 so UNION ALL SELECT 'Request', 20 UNION ALL SELECT 'Complaint', 30) v
WHERE o.office_code <> 'sao';

-- 3) Keep any category text that existing tickets already use (so nothing is lost or renamed)
INSERT IGNORE INTO categories (office_code, name, is_locked, sort_order)
SELECT DISTINCT office_code, category, 0, 55 FROM tickets WHERE category <> 'Other';

-- 4) 'Other' exists in every office (editable like any other category)
INSERT IGNORE INTO categories (office_code, name, is_locked, sort_order) SELECT office_code, 'Other', 0, 999 FROM offices;
UPDATE categories SET is_locked = 0, is_hidden = 0 WHERE name = 'Other';

-- 5) Point tickets at the categories table, then drop the old text column
ALTER TABLE tickets ADD COLUMN category_id INT NULL AFTER user_id;
UPDATE tickets t JOIN categories c ON c.office_code = t.office_code AND c.name = t.category SET t.category_id = c.id;
UPDATE tickets t JOIN categories c ON c.office_code = t.office_code AND c.name = 'Other' SET t.category_id = c.id WHERE t.category_id IS NULL;
ALTER TABLE tickets MODIFY category_id INT NOT NULL, ADD INDEX idx_category (category_id), DROP COLUMN category;
