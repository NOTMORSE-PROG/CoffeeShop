-- ---------------------------------------------------------------------------
-- Support and Customer Service contact details
--
-- Adds shop_facebook and shop_facebook_name settings and sets the shop contact
-- number to 09380312703 for customer service.
-- ---------------------------------------------------------------------------

INSERT INTO `settings` (`key`, `value`, `description`) VALUES
  ('shop_phone',         '09380312703',                                'Contact number shown to customers for support'),
  ('shop_facebook',      'https://www.facebook.com/anthonyjohn.rivera.52', 'Customer service Facebook link'),
  ('shop_facebook_name', 'Anthony John Rivera',                        'Customer service Facebook contact name')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `description` = VALUES(`description`);
