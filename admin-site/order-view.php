<?php
/**
 * One order in full: what was ordered, who for, how it is being paid, where
 * it has been, and what was texted about it.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

start_session();
send_security_headers();
require_login();

$admin   = current_admin();
$orderId = get_int('id');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post_with_csrf();

    $orderId = (int) ($_POST['id'] ?? 0);
    $action  = post_string('action');

    if ($action === 'status') {
        $newStatus = post_string('status');
        $note      = clean_text(post_string('note'), 255);

        $result = change_order_status(
            $orderId,
            $newStatus,
            (int) $admin['id'],
            $note === '' ? null : $note
        );

        if ($result['ok']) {
            flash('success', 'Order moved to ' . status_label($newStatus, (string) $order['order_type']) . '.');
        } else {
            flash('error', (string) $result['error']);
        }
    } elseif ($action === 'payment') {
        $reference = clean_text(post_string('payment_reference'), 80);

        $result = verify_payment($orderId, (int) $admin['id'], $reference === '' ? null : $reference);

        if ($result['ok']) {
            flash('success', 'Payment marked as received.');
        } else {
            flash('error', (string) $result['error']);
        }
    } elseif ($action === 'resend_sms') {
        // For when a customer says the text never arrived. `force` bypasses
        // the per-status switch, because the owner is asking for this one
        // deliberately and it costs a credit either way.
        $resendOrder = find_order($orderId);

        if ($resendOrder === null) {
            flash('error', 'That order no longer exists.');
        } else {
            $status = (string) $resendOrder['status'];
            $logId  = send_order_sms($resendOrder, $status, true);
            $sent   = $logId === null
                ? null
                : db_one('SELECT status, error_message FROM sms_log WHERE id = ?', [$logId]);

            audit(
                'sms.resent',
                'order',
                (string) $resendOrder['order_ref'],
                sprintf('Resent the %s notification for %s',
                    status_label($status, (string) $resendOrder['order_type']),
                    (string) $resendOrder['order_ref']),
                null,
                ['trigger_status' => $status, 'result' => $sent['status'] ?? 'unknown'],
                (int) $admin['id']
            );

            if (($sent['status'] ?? '') === 'sent') {
                flash('success', 'Notification sent again to ' . format_ph_mobile((string) $resendOrder['customer_phone']) . '.');
            } else {
                flash('error', 'The message could not be sent. ' . (string) ($sent['error_message'] ?? ''));
            }
        }
    }

    redirect('order-view.php?id=' . $orderId);
}

$order = $orderId > 0 ? find_order($orderId) : null;

if ($order === null) {
    admin_head('Order not found');
    admin_header('Order not found');
    ?>
    <div class="card">
      <div class="empty">
        <?= admin_icon('icon-alert', 'empty-icon') ?>
        <h3>That order does not exist</h3>
        <p>It may have been removed, or the link may be wrong.</p>
        <a class="btn btn-secondary" href="<?= e(admin_url('orders.php')) ?>">Back to orders</a>
      </div>
    </div>
    <?php
    admin_footer();
    exit;
}

$status     = (string) $order['status'];
$orderType  = (string) $order['order_type'];
$nextStates = allowed_next_statuses($status, $orderType);
$smsHistory = sms_history_for_order((int) $order['id']);

/** sms_log results reuse the shared badge colours rather than inventing new ones. */
$smsBadge = static function (string $result): string {
    return match ($result) {
        'sent'    => 'badge-ready',
        'failed'  => 'badge-cancelled',
        'skipped' => 'badge-completed',
        default   => 'badge-pending',
    };
};

admin_head('Order ' . (string) $order['order_ref']);
admin_header(
    'Order ' . (string) $order['order_ref'],
    'Placed ' . date('j F Y \a\t g:i A', (int) strtotime((string) $order['placed_at']))
);
?>

<p class="mb-4">
  <a class="btn btn-sm btn-ghost" href="<?= e(admin_url('orders.php')) ?>">
    <?= admin_icon('icon-arrow-left', 'icon-sm') ?> All orders
  </a>
</p>

<div class="order-grid">

  <!-- ---------------------------------------------------------------- items -->
  <section class="card">
    <div class="card-header">
      <h2>Items</h2>
      <?= status_badge($status, (string) $order['order_type']) ?>
    </div>

    <div class="table-wrap table-flush">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Item</th>
            <th scope="col">Qty</th>
            <th scope="col" class="right">Line total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($order['items'] as $item): ?>
            <tr>
              <td>
                <span class="bold"><?= e((string) $item['product_name']) ?></span>
                <span class="subtle small">&nbsp;<?= peso($item['unit_price']) ?> each</span>
                <?php if ($item['options'] !== []): ?>
                  <ul class="option-list">
                    <?php foreach ($item['options'] as $option): ?>
                      <li>
                        <span class="subtle"><?= e((string) $option['group_name']) ?>:</span>
                        <?= e((string) $option['option_name']) ?>
                        <?php if ((float) $option['price_delta'] !== 0.0): ?>
                          <span class="accent-text">(<?= peso($option['price_delta']) ?>)</span>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </td>
              <td class="tabular"><?= (int) $item['quantity'] ?></td>
              <td class="tabular right nowrap"><?= peso($item['line_total']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th scope="row" colspan="2">Subtotal</th>
            <td class="tabular right nowrap"><?= peso($order['subtotal']) ?></td>
          </tr>
          <?php if ((float) $order['delivery_fee'] > 0): ?>
            <tr>
              <th scope="row" colspan="2">Delivery fee</th>
              <td class="tabular right nowrap"><?= peso($order['delivery_fee']) ?></td>
            </tr>
          <?php endif; ?>
          <tr class="total-row">
            <th scope="row" colspan="2">Total</th>
            <td class="tabular right nowrap"><?= peso($order['total']) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <?php if (!empty($order['special_instructions'])): ?>
      <div class="card-footer">
        <p class="small mb-0">
          <span class="bold">Note from the customer:</span>
          <?= e((string) $order['special_instructions']) ?>
        </p>
      </div>
    <?php endif; ?>
  </section>

  <!-- ------------------------------------------------------------- customer -->
  <section class="card">
    <div class="card-header"><h2>Customer</h2></div>
    <div class="card-body">
      <dl class="detail-list">
        <dt>Name</dt>
        <dd><?= e((string) $order['customer_name']) ?></dd>

        <dt>Mobile</dt>
        <dd>
          <a href="tel:<?= e(format_ph_mobile((string) $order['customer_phone'])) ?>">
            <?= e(format_ph_mobile((string) $order['customer_phone'])) ?>
          </a>
        </dd>

        <dt>Fulfilment</dt>
        <dd><?= $orderType === 'delivery' ? 'Delivery' : 'Pickup at the shop' ?></dd>

        <?php if ($orderType === 'delivery'): ?>
          <dt>Address</dt>
          <dd><?= e((string) ($order['delivery_address'] ?? '')) ?></dd>

          <dt>City</dt>
          <dd><?= e((string) ($order['delivery_city'] ?? 'Not given')) ?></dd>
        <?php endif; ?>

        <?php if (!empty($order['cancel_reason'])): ?>
          <dt>Cancellation reason</dt>
          <dd><?= e((string) $order['cancel_reason']) ?></dd>
        <?php endif; ?>
      </dl>

      <?php if ($orderType === 'delivery'): ?>
        <p class="hint mb-0">
          <?= e((string) setting('delivery_note', 'The shop owner arranges delivery.')) ?>
        </p>
      <?php endif; ?>
    </div>
  </section>

  <!-- -------------------------------------------------------------- payment -->
  <section class="card">
    <div class="card-header">
      <h2>Payment</h2>
      <?= payment_badge((string) $order['payment_status']) ?>
    </div>
    <div class="card-body">
      <dl class="detail-list">
        <dt>Method</dt>
        <dd><?= $order['payment_method'] === 'gcash' ? 'GCash' : 'Cash at the counter' ?></dd>

        <?php if (!empty($order['payment_reference'])): ?>
          <dt>Reference</dt>
          <dd class="tabular"><?= e((string) $order['payment_reference']) ?></dd>
        <?php endif; ?>

        <?php if (!empty($order['payment_verified_at'])): ?>
          <dt>Confirmed</dt>
          <dd><?= e(date('j M Y, g:i A', (int) strtotime((string) $order['payment_verified_at']))) ?></dd>
        <?php endif; ?>
      </dl>

      <?php if ($order['payment_method'] === 'gcash' && $order['payment_status'] !== 'paid'): ?>
        <div class="alert alert-warning">
          <div>
            GCash here is a static QR code. Check the money actually landed in the shop's
            GCash account before confirming this.
          </div>
        </div>

        <form method="post" action="<?= e(admin_url('order-view.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="payment">
          <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">

          <div class="field">
            <label class="label" for="payment_reference">GCash reference number</label>
            <input class="input" type="text" id="payment_reference" name="payment_reference"
                   value="<?= e((string) ($order['payment_reference'] ?? '')) ?>"
                   maxlength="80" placeholder="Optional, from the GCash receipt">
          </div>

          <button type="submit" class="btn" data-busy-label="Saving"
                  data-confirm="Mark this order as paid?">
            <?= admin_icon('icon-wallet', 'icon-sm') ?> Mark payment as received
          </button>
        </form>
      <?php elseif ($order['payment_method'] === 'cash' && $order['payment_status'] !== 'paid'): ?>
        <p class="small subtle mb-0">The customer pays over the counter when they collect the order.</p>
      <?php endif; ?>
    </div>
  </section>

  <!-- --------------------------------------------------------------- status -->
  <section class="card">
    <div class="card-header"><h2>Move this order on</h2></div>
    <div class="card-body">
      <?php if ($nextStates === []): ?>
        <p class="small subtle mb-0">
          This order is <?= e(status_label($status, (string) $order['order_type'])) ?>. There is nothing further to change.
        </p>
      <?php else: ?>
        <form method="post" action="<?= e(admin_url('order-view.php')) ?>" class="stack">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">

          <div class="choice-group choice-group-2">
            <?php foreach ($nextStates as $index => $candidate): ?>
              <label class="choice">
                <input type="radio" name="status" value="<?= e($candidate) ?>"
                       <?= $index === 0 ? 'checked' : '' ?> required>
                <span>
                  <span class="choice-title"><?= e(status_label($candidate, (string) $order['order_type'])) ?></span>
                  <span class="choice-note">
                    <?php if ($candidate === 'preparing'): ?>
                      Accepts the order and takes the ingredients off stock.
                    <?php elseif ($candidate === 'cancelled'): ?>
                      Puts any deducted stock back.
                    <?php else: ?>
                      Texts the customer if that status has SMS switched on.
                    <?php endif; ?>
                  </span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="field">
            <label class="label" for="note">Note</label>
            <input class="input" type="text" id="note" name="note" maxlength="255"
                   placeholder="Optional. Used as the reason when cancelling.">
          </div>

          <button type="submit" class="btn" data-busy-label="Saving"
                  data-confirm="Update the status of <?= e((string) $order['order_ref']) ?>?">
            Update status
          </button>
        </form>
      <?php endif; ?>
    </div>
  </section>

  <!-- ------------------------------------------------------------- timeline -->
  <section class="card">
    <div class="card-header"><h2>Progress</h2></div>
    <div class="card-body">
      <ol class="steps">
        <?php foreach ($order['history'] as $index => $entry): ?>
          <?php
          $to      = (string) $entry['to_status'];
          $isLast  = $index === count($order['history']) - 1;
          $variant = $to === 'cancelled' ? 'is-cancelled' : ($isLast ? 'is-current' : 'is-done');
          ?>
          <li class="step <?= $variant ?>">
            <span class="step-dot"><?= admin_icon($to === 'cancelled' ? 'icon-x' : 'icon-check', 'icon-sm') ?></span>
            <span class="grow">
              <p class="step-title"><?= e(status_label($to, (string) $order['order_type'])) ?></p>
              <p class="step-note">
                <?= e(date('j M Y, g:i A', (int) strtotime((string) $entry['created_at']))) ?>
                &middot; <?= e((string) ($entry['changed_by_name'] ?? 'System')) ?>
                <?php if (!empty($entry['note'])): ?>
                  <br><?= e((string) $entry['note']) ?>
                <?php endif; ?>
              </p>
            </span>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>

  <!-- ------------------------------------------------------------------ sms -->
  <section class="card order-grid-wide">
    <div class="card-header">
      <h2>SMS history</h2>
      <div class="row">
        <span class="small subtle"><?= count($smsHistory) ?> message<?= count($smsHistory) === 1 ? '' : 's' ?></span>
        <?php if (!in_array($status, ['completed', 'cancelled'], true) || $smsHistory !== []): ?>
          <form method="post" action="<?= e(admin_url('order-view.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="resend_sms">
            <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
            <button type="submit" class="btn btn-sm btn-secondary" data-busy-label="Sending"
                    data-confirm="Send the &quot;<?= e(status_label($status, (string) $order['order_type'])) ?>&quot; message again? This uses one SMS credit.">
              <?= admin_icon('icon-refresh', 'icon-sm') ?> Resend current status
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($smsHistory === []): ?>
      <div class="empty">
        <?= admin_icon('icon-message', 'empty-icon') ?>
        <h3>Nothing sent yet</h3>
        <p class="mb-0">Messages appear here as the order moves through its statuses.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap table-flush">
        <table class="table table-compact">
          <thead>
            <tr>
              <th scope="col">When</th>
              <th scope="col">Trigger</th>
              <th scope="col">Result</th>
              <th scope="col">Message</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($smsHistory as $sms): ?>
              <tr>
                <td class="nowrap small"><?= e(date('j M, g:i A', (int) strtotime((string) $sms['created_at']))) ?></td>
                <td class="small"><?= e(status_label((string) ($sms['trigger_status'] ?? ''))) ?></td>
                <td>
                  <span class="badge <?= e($smsBadge((string) $sms['status'])) ?>">
                    <?= e(ucfirst((string) $sms['status'])) ?>
                  </span>
                </td>
                <td class="small">
                  <?= e((string) $sms['message']) ?>
                  <?php if (!empty($sms['error_message'])): ?>
                    <br><span class="subtle tiny"><?= e((string) $sms['error_message']) ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

</div>

<?php admin_footer(); ?>
