-- Repair the exact four-city list flattened by the old Settings sanitizer.
-- Safe to rerun; custom city lists are left alone.
UPDATE settings
SET value = CONCAT('Mandaluyong', CHAR(10), 'Pasig', CHAR(10), 'Makati', CHAR(10), 'San Juan')
WHERE `key` = 'delivery_cities' AND value = 'Mandaluyong Pasig Makati San Juan';
