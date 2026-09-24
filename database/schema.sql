-- ---------------------------------------------------------------------------
-- Our Coffee Shop - Web-Based Ordering System with SMS Order Status Notification
-- Schema for MySQL 8 / MariaDB 10.4+ (XAMPP)
--
-- Import:  mysql -u root -p < database/schema.sql
-- or open phpMyAdmin, choose Import, and select this file.
-- ---------------------------------------------------------------------------

CREATE DATABASE IF NOT EXISTS `our_coffee_shop`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `our_coffee_shop`;

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

-- ---------------------------------------------------------------------------
-- Settings: shop-wide configuration the owner can change without a deploy
-- ---------------------------------------------------------------------------
CREATE TABLE `settings` (
  `key`         VARCHAR(64)  NOT NULL,
  `value`       TEXT         NULL,
  `description` VARCHAR(255) NULL,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Admin users. Two sides only: admin, and the customer ordering site.
-- `role` separates the owner from any helper account the owner creates.
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- Menu structure
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- Inventory. Stock is deducted when an order is accepted, which is what the
-- paper calls real-time inventory monitoring as orders are processed.
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- Customers. Ordering is guest-based and keyed on the mobile number, because
-- that number is what the SMS notification is sent to.
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- Orders
--
-- Delivery here is a simplified fulfilment option, agreed with the team:
-- the system records the request and shows status. There is deliberately no
-- rider, no coordinates, no tracking URL and no distance-based fee column.
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- SMS log. Every send attempt is recorded so the team can show the panel
-- exactly what went out, and so credit spend stays visible.
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- Security: brute-force throttling and the audit trail
-- ---------------------------------------------------------------------------
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
