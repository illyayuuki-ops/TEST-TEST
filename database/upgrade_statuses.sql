-- New ticket statuses: Reopened, Waiting for User, Escalated, Cancelled (checklist: ticket-status workflow).
-- Run ONCE in phpMyAdmin (select your database -> SQL tab). Existing tickets keep their current status. Back up first.
ALTER TABLE tickets MODIFY status ENUM('Open','Reopened','In Progress','Waiting for User','Escalated','Resolved','Closed','Cancelled') NOT NULL DEFAULT 'Open';
