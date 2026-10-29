-- In-app notifications (bell in the top bar). The app also creates this table by itself on first use.
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  ticket_id INT NULL,
  title VARCHAR(160) NOT NULL,
  body VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  KEY idx_user_read (user_id, read_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
