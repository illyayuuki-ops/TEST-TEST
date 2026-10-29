-- Keep only the Student Affairs (sao) admin. Safe to re-run.
-- Removes the Super Admin and every office admin except SAO. Offices, categories and tickets are NOT touched.
DELETE FROM users WHERE role = 'super_admin';
DELETE FROM users WHERE role = 'admin' AND office_code <> 'sao';
-- Tickets that were assigned to a removed admin go back to the unassigned pool.
UPDATE tickets SET assigned_to = NULL WHERE assigned_to IS NOT NULL AND assigned_to NOT IN (SELECT id FROM users);
