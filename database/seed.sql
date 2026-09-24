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

USE `our_coffee_shop`;

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
