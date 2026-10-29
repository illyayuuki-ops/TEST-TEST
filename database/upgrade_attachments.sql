-- Ticket file attachments (safe to re-run). phpMyAdmin -> select your database -> SQL tab -> run this file.
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
