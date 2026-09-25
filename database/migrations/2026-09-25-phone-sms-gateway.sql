-- ---------------------------------------------------------------------------
-- Phone SMS gateway
--
-- Sending moves from "call an API and wait" to a queue the owner's phone
-- drains. This is the only shape that works on InfinityFree: the server there
-- cannot open a connection to a phone sitting behind a home router or on
-- mobile data, and free hosting is unreliable about outbound calls anyway.
-- So the phone asks the server for work, rather than the server pushing.
--
-- Apply after schema.sql and seed.sql:
--   mysql -u root -p our_coffee_shop < database/migrations/2026-09-25-phone-sms-gateway.sql
-- ---------------------------------------------------------------------------

USE `our_coffee_shop`;

-- --- sms_log gains the columns a queue needs --------------------------------

ALTER TABLE `sms_log`
  ADD COLUMN `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'How many times a device has taken this and not confirmed it'
    AFTER `error_message`,
  ADD COLUMN `claimed_at` DATETIME NULL
    COMMENT 'When a device last took this message, so two devices cannot both send it'
    AFTER `attempts`,
  ADD COLUMN `sent_at` DATETIME NULL
    COMMENT 'When the device confirmed it left the handset'
    AFTER `claimed_at`,
  ADD COLUMN `expires_at` DATETIME NULL
    COMMENT 'After this it is pointless to send. A late "being prepared" text confuses people'
    AFTER `sent_at`,
  ADD COLUMN `device_id` VARCHAR(64) NULL
    COMMENT 'Which handset sent it'
    AFTER `expires_at`;

-- Finding the next batch to hand out is the hot query.
ALTER TABLE `sms_log`
  ADD INDEX `idx_sms_queue` (`status`, `claimed_at`, `id`);

-- --- Settings ----------------------------------------------------------------

INSERT INTO `settings` (`key`, `value`, `description`) VALUES
  ('sms_provider', 'phone',
   'How texts are sent: phone (the shop handset drains a queue), semaphore (paid API), or off'),

  ('sms_device_token', '',
   'Shared secret the handset presents to collect messages. Treat it like a password'),

  ('sms_device_name', '',
   'Label for the handset, so the admin can tell which one is connected'),

  ('sms_device_last_seen', '',
   'Last time the handset contacted the server. Written by the gateway endpoint'),

  ('sms_device_last_ip', '',
   'Where the handset last contacted from'),

  ('sms_batch_size', '5',
   'How many messages the handset may take in one go'),

  ('sms_max_attempts', '3',
   'Give up on a message after this many handovers without confirmation'),

  ('sms_ttl_minutes', '45',
   'A queued message older than this is abandoned rather than sent late'),

  ('sms_claim_timeout_seconds', '120',
   'If a handset takes a message and never confirms, it returns to the queue after this')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- Anything already recorded came from the API, before the queue existed.
UPDATE `sms_log`
SET `sent_at` = `created_at`
WHERE `status` = 'sent' AND `sent_at` IS NULL;
