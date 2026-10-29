<?php
/**
 * Customer Service and Support page.
 *
 * Provides direct contact channels (Phone & Facebook), order assistance,
 * store hours, and answers to frequently asked customer questions.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

$phone        = (string) setting('shop_phone', '09380312703');
if ($phone === '') {
    $phone = '09380312703';
}
$facebook     = (string) setting('shop_facebook', 'https://www.facebook.com/anthonyjohn.rivera.52');
if ($facebook === '') {
    $facebook = 'https://www.facebook.com/anthonyjohn.rivera.52';
}
$facebookName = (string) setting('shop_facebook_name', 'Anthony John Rivera');
if ($facebookName === '') {
    $facebookName = 'Anthony John Rivera';
}
$address      = (string) setting('shop_address', 'Main Street, Mandaluyong City');
$open         = (string) setting('shop_open_time', '11:00');
$close        = (string) setting('shop_close_time', '20:00');

customer_head('Customer Service', 'Contact Our Coffee Shop customer support via phone or Facebook for help with your orders and inquiries.');
?>

<section class="section-sm">
  <div class="container">
    <header class="section-head center mx-auto">
      <p class="eyebrow">Customer Support</p>
      <h1>Customer <span class="accent-text">Service</span></h1>
      <p class="lede mx-auto">
        Have questions about your order, delivery, or menu items? We are here to help you enjoy your daily cup of comfort.
      </p>
    </header>

    <div class="support-channels-grid mb-8">
      <div class="card card-pad support-channel-card">
        <div class="support-channel-icon-wrap phone-accent">
          <?= icon('phone', 'icon icon-lg') ?>
        </div>
        <div class="support-channel-body">
          <p class="support-channel-badge">Hotline &amp; SMS</p>
          <h2 class="h-title mb-1"><?= e($phone) ?></h2>
          <p class="small muted mb-4">
            Call or text us directly for immediate assistance with active orders, pickup questions, or urgent changes.
          </p>
          <a class="btn btn-block" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>">
            <?= icon('phone') ?> Call or Text <?= e($phone) ?>
          </a>
        </div>
      </div>

      <div class="card card-pad support-channel-card">
        <div class="support-channel-icon-wrap fb-accent">
          <?= icon('facebook', 'icon icon-lg') ?>
        </div>
        <div class="support-channel-body">
          <p class="support-channel-badge">Facebook Support</p>
          <h2 class="h-title mb-1"><?= e($facebookName) ?></h2>
          <p class="small muted mb-4">
            Chat with customer service on Facebook for inquiries, feedback, payment confirmations, and support.
          </p>
          <a class="btn btn-block btn-secondary" href="<?= e($facebook) ?>" target="_blank" rel="noopener noreferrer">
            <?= icon('facebook') ?> Message on Facebook
          </a>
        </div>
      </div>
    </div>

    <div class="support-services-grid mb-8">
      <div class="card card-pad">
        <div class="support-service-header">
          <span class="support-mini-icon"><?= icon('search') ?></span>
          <h3 class="h-title mb-0">Track an Order</h3>
        </div>
        <p class="small muted">
          Check live preparation and delivery status anytime using your order reference and mobile number.
        </p>
        <a class="btn btn-sm btn-secondary" href="<?= e(url('track.php')) ?>">Track Order &rarr;</a>
      </div>

      <div class="card card-pad">
        <div class="support-service-header">
          <span class="support-mini-icon"><?= icon('store') ?></span>
          <h3 class="h-title mb-0">Store Hours &amp; Pickup</h3>
        </div>
        <p class="small muted">
          <?= e($address) ?><br>
          Open daily <?= e(date('g:i A', (int) strtotime($open))) ?> to <?= e(date('g:i A', (int) strtotime($close))) ?>.
        </p>
        <a class="btn btn-sm btn-secondary" href="<?= e(url('menu.php')) ?>">Browse Menu &rarr;</a>
      </div>

      <div class="card card-pad">
        <div class="support-service-header">
          <span class="support-mini-icon"><?= icon('message') ?></span>
          <h3 class="h-title mb-0">SMS Notifications</h3>
        </div>
        <p class="small muted">
          Our system automatically sends free SMS updates to your mobile phone when your order is confirmed, preparing, and ready.
        </p>
        <a class="btn btn-sm btn-secondary" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>">Ask via SMS &rarr;</a>
      </div>
    </div>

    <div class="card card-pad faq-card">
      <h2 class="h-title mb-4">Frequently Asked Questions</h2>

      <div class="faq-item">
        <h3 class="faq-question">How do I track my order status?</h3>
        <p class="small muted">
          You will receive automatic SMS updates at every stage. You can also visit our
          <a href="<?= e(url('track.php')) ?>">Track Order page</a> and enter your order reference (e.g. ORD-YYYYMMDD-XXXX)
          along with the 10-digit mobile number you used at checkout.
        </p>
      </div>

      <div class="faq-item">
        <h3 class="faq-question">Can I change or cancel my order after placing it?</h3>
        <p class="small muted">
          Because drinks are prepared freshly right after the order is received, please contact customer service
          immediately via phone (<a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= e($phone) ?></a>)
          or on <a href="<?= e($facebook) ?>" target="_blank" rel="noopener noreferrer">Facebook (<?= e($facebookName) ?>)</a>.
        </p>
      </div>

      <div class="faq-item">
        <h3 class="faq-question">How does delivery work?</h3>
        <p class="small muted">
          We offer simplified local delivery to select cities. The shop owner arranges the delivery personally or
          books a courier for you once your order is packed and ready.
        </p>
      </div>

      <div class="faq-item">
        <h3 class="faq-question">What payment methods are supported?</h3>
        <p class="small muted">
          We accept Cash on Delivery and QR payments (GCash / Maya). For QR payments, scan the QR code presented at
          checkout and our team confirms the receipt manually upon preparation.
        </p>
      </div>
    </div>
  </div>
</section>

<?php customer_footer(); ?>
