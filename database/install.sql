-- ---------------------------------------------------------------------------
-- Our Coffee Shop - complete installer
--
-- Everything in one file: tables, menu, settings and the owner account.
-- Import this and the system is ready to run.
--
-- On shared hosting the database already exists and is named for you, so the
-- CREATE DATABASE and USE lines are deliberately absent. Select your database
-- in phpMyAdmin first, then import this file.
--
-- Locally you can instead run:
--   mysql -u root -p our_coffee_shop < database/install.sql
-- ---------------------------------------------------------------------------

-- ===== Tables =====


SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `sms_log`;
DROP TABLE IF EXISTS `order_status_history`;
DROP TABLE IF EXISTS `order_item_options`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `product_ingredients`;
DROP TABLE IF EXISTS `product_option_groups`;
DROP TABLE IF EXISTS `options`;
DROP TABLE IF EXISTS `option_groups`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `inventory_items`;
DROP TABLE IF EXISTS `admin_users`;
DROP TABLE IF EXISTS `settings`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `settings` (
  `key`         VARCHAR(64)  NOT NULL,
  `value`       TEXT         NULL,
  `description` VARCHAR(255) NULL,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admin_users` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`        VARCHAR(50)  NOT NULL,
  `full_name`       VARCHAR(120) NOT NULL,
  `email`           VARCHAR(160) NULL,
  `password_hash`   VARCHAR(255) NOT NULL,
  `role`            ENUM('owner','staff') NOT NULL DEFAULT 'staff',
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `last_login_at`   DATETIME     NULL,
  `last_login_ip`   VARCHAR(45)  NULL,
  `locked_until`    DATETIME     NULL,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_username` (`username`),
  KEY `idx_admin_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(80)  NOT NULL,
  `slug`        VARCHAR(80)  NOT NULL,
  `description` VARCHAR(255) NULL,
  `sort_order`  SMALLINT     NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_category_slug` (`slug`),
  KEY `idx_category_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `products` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id`   INT UNSIGNED NOT NULL,
  `name`          VARCHAR(120) NOT NULL,
  `slug`          VARCHAR(140) NOT NULL,
  `description`   VARCHAR(400) NULL,
  `price`         DECIMAL(10,2) NOT NULL,
  `image_path`    VARCHAR(255) NULL,
  `is_available`  TINYINT(1)   NOT NULL DEFAULT 1,
  `is_featured`   TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`    SMALLINT     NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_slug` (`slug`),
  KEY `idx_product_category` (`category_id`),
  KEY `idx_product_available` (`is_available`),
  CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`)
    REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customisation, e.g. Sugar Level (pick one), Add-ons (pick many)
CREATE TABLE `option_groups` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(80)  NOT NULL,
  `selection_type` ENUM('single','multiple') NOT NULL DEFAULT 'single',
  `is_required`    TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`     SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `options` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`    INT UNSIGNED NOT NULL,
  `name`        VARCHAR(80)  NOT NULL,
  `price_delta` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `is_default`  TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`  SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_option_group` (`group_id`),
  CONSTRAINT `fk_option_group` FOREIGN KEY (`group_id`)
    REFERENCES `option_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_option_groups` (
  `product_id` INT UNSIGNED NOT NULL,
  `group_id`   INT UNSIGNED NOT NULL,
  PRIMARY KEY (`product_id`, `group_id`),
  KEY `idx_pog_group` (`group_id`),
  CONSTRAINT `fk_pog_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pog_group` FOREIGN KEY (`group_id`)
    REFERENCES `option_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_items` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(120) NOT NULL,
  `unit`          VARCHAR(24)  NOT NULL DEFAULT 'pc',
  `stock_qty`     DECIMAL(12,3) NOT NULL DEFAULT 0,
  `reorder_level` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventory_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_ingredients` (
  `product_id`        INT UNSIGNED NOT NULL,
  `inventory_item_id` INT UNSIGNED NOT NULL,
  `qty_per_unit`      DECIMAL(12,3) NOT NULL DEFAULT 1,
  PRIMARY KEY (`product_id`, `inventory_item_id`),
  KEY `idx_pi_item` (`inventory_item_id`),
  CONSTRAINT `fk_pi_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pi_item` FOREIGN KEY (`inventory_item_id`)
    REFERENCES `inventory_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customers` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(120) NOT NULL,
  `phone`        VARCHAR(20)  NOT NULL,
  `email`        VARCHAR(160) NULL,
  `order_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `orders` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_ref`            VARCHAR(24)  NOT NULL,
  `customer_id`          INT UNSIGNED NULL,
  `customer_name`        VARCHAR(120) NOT NULL,
  `customer_phone`       VARCHAR(20)  NOT NULL,
  `order_type`           ENUM('pickup','delivery') NOT NULL DEFAULT 'pickup',
  `delivery_address`     VARCHAR(255) NULL,
  `delivery_city`        VARCHAR(80)  NULL,
  `payment_method`       ENUM('cash','gcash') NOT NULL DEFAULT 'cash',
  `payment_status`       ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  `payment_reference`    VARCHAR(80)  NULL COMMENT 'GCash reference typed by the customer, verified manually by the admin',
  `payment_verified_by`  INT UNSIGNED NULL,
  `payment_verified_at`  DATETIME     NULL,
  `status`               ENUM('pending','preparing','ready','out_for_delivery','completed','cancelled')
                         NOT NULL DEFAULT 'pending',
  `special_instructions` VARCHAR(400) NULL,
  `subtotal`             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `delivery_fee`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total`                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `stock_deducted`       TINYINT(1)   NOT NULL DEFAULT 0,
  `cancel_reason`        VARCHAR(255) NULL,
  `placed_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`         DATETIME     NULL,
  `updated_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_ref` (`order_ref`),
  KEY `idx_order_status` (`status`),
  KEY `idx_order_placed` (`placed_at`),
  KEY `idx_order_phone` (`customer_phone`),
  KEY `idx_order_customer` (`customer_id`),
  CONSTRAINT `fk_order_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_verifier` FOREIGN KEY (`payment_verified_by`)
    REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Name and price are snapshotted so an old receipt still reads correctly
-- after the owner renames a drink or changes its price.
CREATE TABLE `order_items` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      INT UNSIGNED NOT NULL,
  `product_id`    INT UNSIGNED NULL,
  `product_name`  VARCHAR(120) NOT NULL,
  `unit_price`    DECIMAL(10,2) NOT NULL,
  `quantity`      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `options_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `line_total`    DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_oi_order` (`order_id`),
  KEY `idx_oi_product` (`product_id`),
  CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_oi_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_item_options` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_item_id` INT UNSIGNED NOT NULL,
  `group_name`    VARCHAR(80)  NOT NULL,
  `option_name`   VARCHAR(80)  NOT NULL,
  `price_delta`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_oio_item` (`order_item_id`),
  CONSTRAINT `fk_oio_item` FOREIGN KEY (`order_item_id`)
    REFERENCES `order_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_status_history` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`    INT UNSIGNED NOT NULL,
  `from_status` VARCHAR(24)  NULL,
  `to_status`   VARCHAR(24)  NOT NULL,
  `changed_by`  INT UNSIGNED NULL COMMENT 'NULL means the system, e.g. order placement',
  `note`        VARCHAR(255) NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_osh_order` (`order_id`),
  CONSTRAINT `fk_osh_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_osh_admin` FOREIGN KEY (`changed_by`)
    REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sms_log` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`            INT UNSIGNED NULL,
  `phone`               VARCHAR(20)  NOT NULL,
  `message`             VARCHAR(640) NOT NULL,
  `trigger_status`      VARCHAR(24)  NULL,
  `status`              ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  `provider`            VARCHAR(40)  NOT NULL DEFAULT 'semaphore',
  `provider_message_id` VARCHAR(80)  NULL,
  `error_message`       VARCHAR(255) NULL,
  `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sms_order` (`order_id`),
  KEY `idx_sms_status` (`status`),
  KEY `idx_sms_created` (`created_at`),
  CONSTRAINT `fk_sms_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`    VARCHAR(50)  NOT NULL,
  `ip_address`  VARCHAR(45)  NOT NULL,
  `successful`  TINYINT(1)   NOT NULL DEFAULT 0,
  `user_agent`  VARCHAR(255) NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_la_username_time` (`username`, `created_at`),
  KEY `idx_la_ip_time` (`ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`       INT UNSIGNED NULL,
  `admin_username` VARCHAR(50)  NULL COMMENT 'Kept even if the account is later deleted',
  `action`         VARCHAR(60)  NOT NULL COMMENT 'e.g. order.status_changed, product.updated, auth.login',
  `entity_type`    VARCHAR(40)  NULL,
  `entity_id`      VARCHAR(40)  NULL,
  `summary`        VARCHAR(255) NULL,
  `old_values`     TEXT         NULL,
  `new_values`     TEXT         NULL,
  `ip_address`     VARCHAR(45)  NULL,
  `user_agent`     VARCHAR(255) NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_admin` (`admin_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_entity` (`entity_type`, `entity_id`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_admin` FOREIGN KEY (`admin_id`)
    REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Queue columns and settings for the phone gateway =====
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

-- ===== Menu, inventory, settings, owner account =====
-- ---------------------------------------------------------------------------
-- Our Coffee Shop - seed data
--
-- Menu categories, drinks and prices are taken from Appendix H (Wireframes)
-- of the capstone paper. The owner can rename or reprice anything from the
-- admin site afterwards; nothing here is hard-coded in the application.
--
-- Import AFTER schema.sql:
--   mysql -u root -p our_coffee_shop < database/seed.sql
-- ---------------------------------------------------------------------------


-- ---------------------------------------------------------------------------
-- Shop settings
-- ---------------------------------------------------------------------------
INSERT INTO `settings` (`key`, `value`, `description`) VALUES
  ('shop_name',            'Our Coffee Shop',        'Display name used across both sites'),
  ('shop_tagline',         'coffee is always a good idea', 'Tagline shown under the hero heading'),
  ('shop_phone',           '',                       'Contact number shown to customers'),
  ('shop_address',         'Main Street, Mandaluyong City', 'Shop address shown on the contact section'),
  ('shop_open_time',       '07:00',                  'Daily opening time, 24-hour format'),
  ('shop_close_time',      '20:00',                  'Daily closing time, 24-hour format'),
  ('accepting_orders',     '1',                      'Master switch. 0 puts the ordering site into closed mode'),
  ('delivery_enabled',     '1',                      'Whether customers may choose delivery'),
  ('delivery_fee',         '0.00',                   'Flat delivery fee. The owner sets this manually, there is no distance calculation'),
  ('free_delivery_city',   'Mandaluyong',            'City that gets free delivery'),
  ('delivery_note',        'The shop owner arranges delivery personally or books a courier for you.',
                           'Shown at checkout so expectations are clear'),
  ('payment_cash_enabled', '1',                      'Allow pay over the counter'),
  ('payment_gcash_enabled','1',                      'Allow GCash. This is a static QR plus manual confirmation, not a merchant API'),
  ('gcash_name',           '',                       'GCash account name shown beside the QR'),
  ('gcash_number',         '',                       'GCash number shown beside the QR'),
  ('gcash_qr_path',        'assets/img/gcash-qr.png','Path to the static GCash QR image'),
  ('sms_enabled',          '1',                      'Master switch for SMS sending'),
  ('sms_sender_name',      'OURCOFFEE',              'Registered Semaphore sender name'),
  ('sms_on_pending',       '1',                      'Send an SMS when the order is received'),
  ('sms_on_preparing',     '1',                      'Send an SMS when the order is being prepared'),
  ('sms_on_ready',         '1',                      'Send an SMS when the order is ready'),
  ('sms_on_out_for_delivery','1',                    'Send an SMS when the order is out for delivery'),
  ('sms_on_completed',     '0',                      'Off by default to save credits'),
  ('sms_on_cancelled',     '1',                      'Send an SMS when the order is cancelled'),
  ('low_stock_alert',      '1',                      'Warn the admin when an inventory item is at or below its reorder level');

-- ---------------------------------------------------------------------------
-- Default owner account
--
-- Username: owner
-- Password: OurCoffee2026!
--
-- `must_change_password` is 1, so the system forces a new password on first
-- login. Change this before the system is shown or deployed.
-- ---------------------------------------------------------------------------
INSERT INTO `admin_users` (`username`, `full_name`, `email`, `password_hash`, `role`, `must_change_password`) VALUES
  ('owner', 'Shop Owner', NULL,
   '$2y$10$b1m/tTffcktnNNwL1Fruiex8JRe1J3S4QTHyUF.OnoVnmYxwz80lO',
   'owner', 1);

-- ---------------------------------------------------------------------------
-- Categories
-- ---------------------------------------------------------------------------
INSERT INTO `categories` (`id`, `name`, `slug`, `description`, `sort_order`) VALUES
  (1, 'Espresso Base',   'espresso-base',   'Pulled fresh, built on a double shot',        1),
  (2, 'Frappe',          'frappe',          'Blended cold, thick and sweet',               2),
  (3, 'Non Coffee',      'non-coffee',      'All the comfort, none of the caffeine',       3),
  (4, 'Fruity Soda',     'fruity-soda',     'Cold, fizzy and bright',                      4),
  (5, 'Signature Drink', 'signature-drink', 'The ones regulars keep coming back for',      5);

-- ---------------------------------------------------------------------------
-- Products
-- ---------------------------------------------------------------------------
INSERT INTO `products` (`category_id`, `name`, `slug`, `description`, `price`, `image_path`, `is_featured`, `sort_order`) VALUES
  -- Espresso Base, P95
  (1, 'Salted Caramel',    'salted-caramel',    'Double shot, caramel and a clean line of sea salt.',        95.00, 'assets/img/products/salted-caramel.svg',    1, 1),
  (1, 'Caramel Macchiato', 'caramel-macchiato', 'Steamed milk, vanilla, espresso poured over the top.',      95.00, 'assets/img/products/caramel-macchiato.svg', 0, 2),
  (1, 'Mocha',             'mocha',             'Espresso and dark chocolate, the dependable one.',          95.00, 'assets/img/products/mocha.svg',             0, 3),
  (1, 'Vanilla Latte',     'vanilla-latte',     'Soft vanilla over a smooth double shot.',                   95.00, 'assets/img/products/vanilla-latte.svg',     0, 4),
  (1, 'Spanish Latte',     'spanish-latte',     'Sweetened milk, espresso, served cold.',                    95.00, 'assets/img/products/spanish-latte.svg',     0, 5),

  -- Frappe, P99
  (2, 'Belgium Chocolate', 'belgium-chocolate-frappe', 'Blended chocolate, thick enough to need a spoon.',   99.00, 'assets/img/products/belgium-chocolate-frappe.svg', 1, 1),
  (2, 'Java Chip',         'java-chip',         'Coffee, chocolate chips, blended cold.',                    99.00, 'assets/img/products/java-chip.svg',         0, 2),
  (2, 'Matcha Frappe',     'matcha-frappe',     'Stone-ground matcha, blended smooth.',                      99.00, 'assets/img/products/matcha-frappe.svg',     0, 3),
  (2, 'Cookies and Cream', 'cookies-and-cream', 'Crushed cookies folded through cold cream.',                99.00, 'assets/img/products/cookies-and-cream.svg', 0, 4),
  (2, 'Caramel Frappe',    'caramel-frappe',    'Caramel all the way through, blended cold.',                99.00, 'assets/img/products/caramel-frappe.svg',    0, 5),

  -- Non Coffee, P75
  (3, 'Matcha',            'matcha',            'Just matcha and milk, nothing in the way.',                 75.00, 'assets/img/products/matcha.svg',            0, 1),
  (3, 'Belgium Chocolate', 'belgium-chocolate', 'Rich cocoa, served hot or cold.',                           75.00, 'assets/img/products/belgium-chocolate.svg', 0, 2),
  (3, 'White Chocolate',   'white-chocolate',   'Sweet, creamy, easy to finish.',                            75.00, 'assets/img/products/white-chocolate.svg',   0, 3),
  (3, 'Strawberry Milk',   'strawberry-milk',   'Cold milk and real strawberry.',                            75.00, 'assets/img/products/strawberry-milk.svg',   0, 4),

  -- Fruity Soda, P50
  (4, 'Lemon Soda',        'lemon-soda',        'Sharp, cold and properly fizzy.',                           50.00, 'assets/img/products/lemon-soda.svg',        0, 1),
  (4, 'Mango Soda',        'mango-soda',        'Ripe mango over soda and ice.',                             50.00, 'assets/img/products/mango-soda.svg',        0, 2),
  (4, 'Blueberry Soda',    'blueberry-soda',    'Deep blueberry, long and cold.',                            50.00, 'assets/img/products/blueberry-soda.svg',    0, 3),
  (4, 'Green Apple Soda',  'green-apple-soda',  'Tart green apple, very cold.',                              50.00, 'assets/img/products/green-apple-soda.svg',  0, 4),

  -- Signature
  (5, 'Strawberry Chocolate', 'strawberry-chocolate', 'Strawberry and chocolate, layered.',                  75.00, 'assets/img/products/strawberry-chocolate.svg', 1, 1),
  (5, 'OuR Barista Drink',    'our-barista-drink',    'Whatever the barista is proud of today.',             99.00, 'assets/img/products/our-barista-drink.svg',    1, 2);

-- ---------------------------------------------------------------------------
-- Customisation groups, as described in the Scope: sugar level and add-ons
-- ---------------------------------------------------------------------------
INSERT INTO `option_groups` (`id`, `name`, `selection_type`, `is_required`, `sort_order`) VALUES
  (1, 'Size',        'single',   1, 1),
  (2, 'Sugar Level', 'single',   1, 2),
  (3, 'Add-ons',     'multiple', 0, 3);

INSERT INTO `options` (`group_id`, `name`, `price_delta`, `is_default`, `sort_order`) VALUES
  (1, 'Regular',      0.00,  1, 1),
  (1, 'Large',        20.00, 0, 2),
  (2, 'No Sugar',     0.00,  0, 1),
  (2, '25%',          0.00,  0, 2),
  (2, '50%',          0.00,  1, 3),
  (2, '75%',          0.00,  0, 4),
  (2, '100%',         0.00,  0, 5),
  (3, 'Extra Shot',   25.00, 0, 1),
  (3, 'Pearls',       15.00, 0, 2),
  (3, 'Cream Cheese', 20.00, 0, 3),
  (3, 'Extra Ice',    0.00,  0, 4);

-- Size and sugar apply to every drink, add-ons to the coffee-based ones
INSERT INTO `product_option_groups` (`product_id`, `group_id`)
  SELECT `id`, 1 FROM `products`;
INSERT INTO `product_option_groups` (`product_id`, `group_id`)
  SELECT `id`, 2 FROM `products`;
INSERT INTO `product_option_groups` (`product_id`, `group_id`)
  SELECT `id`, 3 FROM `products` WHERE `category_id` IN (1, 2, 5);

-- ---------------------------------------------------------------------------
-- Inventory
-- ---------------------------------------------------------------------------
INSERT INTO `inventory_items` (`id`, `name`, `unit`, `stock_qty`, `reorder_level`) VALUES
  (1,  'Espresso Beans',     'g',   5000, 1000),
  (2,  'Fresh Milk',         'ml', 20000, 4000),
  (3,  'Chocolate Syrup',    'ml',  3000,  600),
  (4,  'Caramel Syrup',      'ml',  3000,  600),
  (5,  'Vanilla Syrup',      'ml',  2000,  400),
  (6,  'Matcha Powder',      'g',   1000,  250),
  (7,  'White Chocolate',    'g',   1500,  300),
  (8,  'Strawberry Syrup',   'ml',  2000,  400),
  (9,  'Lemon Syrup',        'ml',  2000,  400),
  (10, 'Mango Syrup',        'ml',  2000,  400),
  (11, 'Blueberry Syrup',    'ml',  2000,  400),
  (12, 'Green Apple Syrup',  'ml',  2000,  400),
  (13, 'Soda Water',         'ml', 15000, 3000),
  (14, 'Cookie Crumbs',      'g',   1200,  300),
  (15, 'Cups 16oz',          'pc',   500,  100),
  (16, 'Cups 22oz',          'pc',   300,   80),
  (17, 'Straws',             'pc',   800,  150),
  (18, 'Sea Salt',           'g',    500,  100);

-- Recipes. These drive the stock deduction when an order is accepted.
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 15, 1 FROM `products` p;                                   -- every drink uses a cup
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 17, 1 FROM `products` p;                                   -- and a straw
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 1, 18 FROM `products` p WHERE p.`category_id` IN (1, 5);   -- espresso base
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 1, 12 FROM `products` p WHERE p.`slug` = 'java-chip';
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 2, 180 FROM `products` p WHERE p.`category_id` IN (1, 2, 3, 5);
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 13, 200 FROM `products` p WHERE p.`category_id` = 4;
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 3, 30 FROM `products` p WHERE p.`slug` IN ('mocha','belgium-chocolate','belgium-chocolate-frappe','java-chip','strawberry-chocolate');
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 4, 30 FROM `products` p WHERE p.`slug` IN ('salted-caramel','caramel-macchiato','caramel-frappe');
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 5, 25 FROM `products` p WHERE p.`slug` IN ('vanilla-latte','caramel-macchiato','spanish-latte');
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 6, 12 FROM `products` p WHERE p.`slug` IN ('matcha','matcha-frappe');
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 7, 25 FROM `products` p WHERE p.`slug` = 'white-chocolate';
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 8, 25 FROM `products` p WHERE p.`slug` IN ('strawberry-milk','strawberry-chocolate');
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 9, 30 FROM `products` p WHERE p.`slug` = 'lemon-soda';
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 10, 30 FROM `products` p WHERE p.`slug` = 'mango-soda';
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 11, 30 FROM `products` p WHERE p.`slug` = 'blueberry-soda';
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 12, 30 FROM `products` p WHERE p.`slug` = 'green-apple-soda';
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 14, 20 FROM `products` p WHERE p.`slug` = 'cookies-and-cream';
INSERT INTO `product_ingredients` (`product_id`, `inventory_item_id`, `qty_per_unit`)
SELECT p.`id`, 18, 2 FROM `products` p WHERE p.`slug` = 'salted-caramel';
