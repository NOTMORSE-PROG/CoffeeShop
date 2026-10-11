-- ---------------------------------------------------------------------------
-- Maya alongside GCash
--
-- The shop takes a second e-wallet. It works exactly the way GCash does here:
-- a static QR the customer scans, and the owner confirming the payment by hand
-- on the order. No merchant API, no payment processor - the same arrangement
-- agreed for GCash, with a second wallet behind it.
--
-- Run against the shop database:
--   mysql -u <user> -p <database> < 2026-10-11-maya-wallet.sql
-- ---------------------------------------------------------------------------

-- An order can now be paid by Maya. Existing rows keep their method; the
-- column default is unchanged.
ALTER TABLE `orders`
  MODIFY `payment_method` ENUM('cash','gcash','maya') NOT NULL DEFAULT 'cash';

-- The same four settings GCash has. Off by default: a wallet with no QR
-- uploaded should not be offered to customers.
INSERT INTO `settings` (`key`, `value`, `description`) VALUES
  ('payment_maya_enabled', '0',  'Let customers pay by Maya. They scan your QR and you confirm it yourself.'),
  ('maya_name',            '',   'The account name shown beside your QR.'),
  ('maya_number',          '',   'The number shown beside your QR.'),
  ('maya_qr_path',         '',   'Uploaded through Settings; not edited by hand.')
ON DUPLICATE KEY UPDATE `key` = `key`;
