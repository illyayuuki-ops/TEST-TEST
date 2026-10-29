-- Run once on an existing database: 'Other' becomes a normal, editable category.
UPDATE categories SET is_locked = 0;
