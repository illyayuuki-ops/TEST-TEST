-- Clean-up after the first upgrade run: it created 4 duplicate offices because your real offices use other codes.
-- Copies the checklist categories into your real offices, then removes the empty duplicates. Safe to re-run.
-- Only deletes a duplicate if it has no tickets and no users.
INSERT IGNORE INTO categories (office_code,name,is_locked,sort_order) SELECT 'acads', name,0,sort_order FROM categories WHERE office_code='academic' AND is_locked=0;
INSERT IGNORE INTO categories (office_code,name,is_locked,sort_order) SELECT 'aea',   name,0,sort_order FROM categories WHERE office_code='alumni'   AND is_locked=0;
INSERT IGNORE INTO categories (office_code,name,is_locked,sort_order) SELECT 'guide', name,0,sort_order FROM categories WHERE office_code='guidance' AND is_locked=0;
INSERT IGNORE INTO categories (office_code,name,is_locked,sort_order) SELECT 'main',  name,0,sort_order FROM categories WHERE office_code='maintenance' AND is_locked=0;

DELETE FROM offices WHERE office_code IN ('academic','alumni','guidance','maintenance')
  AND NOT EXISTS (SELECT 1 FROM tickets t WHERE t.office_code=offices.office_code)
  AND NOT EXISTS (SELECT 1 FROM users u WHERE u.office_code=offices.office_code);
