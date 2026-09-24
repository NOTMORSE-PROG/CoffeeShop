/* ---------------------------------------------------------------------------
   Admin site behaviour.

   Three jobs: the collapsing sidebar, the live order queue, and the analytics
   charts. Everything else on the admin site is a plain form post, so the
   pages still work with this file blocked.

   The Content-Security-Policy is script-src 'self' and style-src 'self', so
   there is no inline script anywhere and no style attribute in any markup.
   Figures reach the charts through a <script type="application/json"> block,
   which is data rather than code and is therefore allowed.
   --------------------------------------------------------------------------- */

(function () {
  'use strict';

  /** The computed value of a design token, e.g. token('--accent'). */
  function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  }

  /** A token colour at a given opacity, for chart fills. */
  function fade(hex, alpha) {
    var value = hex.replace('#', '');

    if (value.length === 3) {
      value = value[0] + value[0] + value[1] + value[1] + value[2] + value[2];
    }

    if (value.length !== 6) return hex;

    var int = parseInt(value, 16);

    return 'rgba(' + ((int >> 16) & 255) + ',' + ((int >> 8) & 255) + ',' + (int & 255) + ',' + alpha + ')';
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  /* --- Sidebar ---------------------------------------------------------------
     Off-canvas under 900px. The scrim and the Escape key both close it.      */

  (function sidebar() {
    var aside = document.getElementById('admin-sidebar');
    var toggle = document.querySelector('[data-sidebar-toggle]');
    var scrim = document.querySelector('[data-sidebar-close]');

    if (!aside || !toggle) return;

    function setOpen(open) {
      aside.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (scrim) scrim.hidden = !open;
    }

    toggle.addEventListener('click', function () {
      setOpen(!aside.classList.contains('is-open'));
    });

    if (scrim) {
      scrim.addEventListener('click', function () {
        setOpen(false);
      });
    }

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && aside.classList.contains('is-open')) {
        setOpen(false);
        toggle.focus();
      }
    });

    // Following a link should not leave the panel open behind the new page.
    aside.addEventListener('click', function (event) {
      if (event.target.closest('a')) setOpen(false);
    });
  })();

  /* --- Live order queue --------------------------------------------------------
     Polls api/queue.php every fifteen seconds. The list is only rebuilt when
     the data actually changed, so a button is never yanked out from under a
     click that is already happening.                                          */

  (function queue() {
    var list = document.getElementById('order-queue');
    if (!list) return;

    var url = list.getAttribute('data-queue-url');
    var action = list.getAttribute('data-queue-action');
    var csrf = list.getAttribute('data-csrf');
    var emptyState = document.querySelector('[data-queue-empty]');
    var stamp = document.querySelector('[data-queue-updated]');

    if (!url || !window.OurCoffee || !window.OurCoffee.poll) return;

    var lastSignature = null;

    function hiddenInput(name, value) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      return input;
    }

    function buildItem(order) {
      var item = el('li', 'queue-item');
      item.setAttribute('data-order-id', String(order.id));

      var body = el('div', 'queue-body');
      var head = el('div', 'queue-head');

      var ref = el('a', 'queue-ref', order.order_ref);
      ref.href = order.view_url;
      head.appendChild(ref);

      head.appendChild(el('span', 'badge badge-' + order.status, order.status_label));
      head.appendChild(el(
        'span',
        'badge badge-' + order.payment_status,
        order.payment_status === 'paid' ? 'Paid' : 'Unpaid'
      ));

      body.appendChild(head);
      body.appendChild(el('p', 'queue-customer', order.customer_name + ' · ' + order.customer_phone));
      body.appendChild(el('p', 'queue-meta',
        order.type_label
        + ' · ' + order.item_count + (order.item_count === 1 ? ' item' : ' items')
        + ' · ' + order.total_display
        + ' · ' + order.placed_at + ' (' + order.waiting + ')'
      ));

      var actions = el('div', 'queue-actions');

      order.next_statuses.forEach(function (next) {
        var form = document.createElement('form');
        form.method = 'post';
        form.action = action;
        form.setAttribute('data-no-guard', '');

        form.appendChild(hiddenInput('_csrf', csrf));
        form.appendChild(hiddenInput('order_id', String(order.id)));
        form.appendChild(hiddenInput('status', next.status));

        var button = el('button', 'btn btn-sm' + (next.status === 'cancelled' ? ' btn-danger' : ''), next.label);
        button.type = 'submit';

        if (next.status === 'cancelled') {
          button.setAttribute('data-confirm', 'Cancel order ' + order.order_ref + '?');
        }

        form.appendChild(button);
        actions.appendChild(form);
      });

      var open = el('a', 'btn btn-sm btn-secondary', 'Open');
      open.href = order.view_url;
      actions.appendChild(open);

      item.appendChild(body);
      item.appendChild(actions);

      return item;
    }

    function render(data) {
      if (!data || !data.orders) return;

      if (data.stats) {
        Object.keys(data.stats).forEach(function (key) {
          var tile = document.querySelector('[data-stat="' + key + '"] [data-stat-value]');
          if (!tile) return;

          var display = key === 'today_revenue' ? data.stats.today_revenue_display : data.stats[key];
          tile.textContent = String(display);
        });
      }

      var signature = JSON.stringify(data.orders);

      if (signature !== lastSignature) {
        lastSignature = signature;

        list.textContent = '';
        data.orders.forEach(function (order) {
          list.appendChild(buildItem(order));
        });

        if (emptyState) emptyState.hidden = data.orders.length > 0;
      }

      if (stamp) {
        stamp.textContent = 'Updated ' + new Date().toLocaleTimeString([], {
          hour: 'numeric',
          minute: '2-digit'
        });
      }
    }

    window.OurCoffee.poll(url, 15000, render);
  })();

  /* --- Analytics charts ---------------------------------------------------------- */

  (function charts() {
    var source = document.getElementById('analytics-data');
    if (!source || typeof Chart === 'undefined') return;

    var data;

    try {
      data = JSON.parse(source.textContent);
    } catch (error) {
      return;
    }

    var accent = token('--red-600');
    var ink = token('--ink-500');
    var grid = token('--cream-300');
    var surface = token('--bg-surface');

    var statusColours = {
      pending: token('--status-pending'),
      preparing: token('--status-preparing'),
      ready: token('--status-ready'),
      out_for_delivery: token('--status-delivery'),
      completed: token('--status-completed'),
      cancelled: token('--status-cancelled')
    };

    var smsColours = {
      sent: token('--status-ready'),
      failed: token('--status-cancelled'),
      skipped: token('--status-completed'),
      queued: token('--status-pending')
    };

    Chart.defaults.font.family = token('--font-body') || 'system-ui, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = ink;
    Chart.defaults.plugins.legend.labels.boxWidth = 12;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.maintainAspectRatio = false;

    function canvas(id) {
      return document.getElementById(id);
    }

    function money(value) {
      return window.OurCoffee ? window.OurCoffee.peso(value) : value;
    }

    var axis = {
      grid: { color: grid, drawBorder: false },
      ticks: { color: ink },
      border: { display: false }
    };

    // --- Revenue over the range, with the order count behind it ---------------
    var revenueCanvas = canvas('chart-revenue');

    if (revenueCanvas) {
      new Chart(revenueCanvas, {
        type: 'line',
        data: {
          labels: data.revenue.labels,
          datasets: [
            {
              label: 'Revenue',
              data: data.revenue.revenue,
              borderColor: accent,
              backgroundColor: fade(accent, 0.12),
              borderWidth: 2.5,
              pointRadius: 3,
              pointBackgroundColor: surface,
              pointBorderColor: accent,
              pointBorderWidth: 2,
              tension: 0.3,
              fill: true,
              yAxisID: 'y'
            },
            {
              label: 'Orders',
              data: data.revenue.orders,
              borderColor: statusColours.completed,
              backgroundColor: 'transparent',
              borderWidth: 1.5,
              borderDash: [5, 4],
              pointRadius: 0,
              tension: 0.3,
              yAxisID: 'yOrders'
            }
          ]
        },
        options: {
          responsive: true,
          interaction: { mode: 'index', intersect: false },
          scales: {
            x: axis,
            y: {
              grid: axis.grid,
              border: axis.border,
              beginAtZero: true,
              ticks: {
                color: ink,
                callback: function (value) { return money(value); }
              }
            },
            yOrders: {
              position: 'right',
              beginAtZero: true,
              grid: { display: false },
              border: { display: false },
              ticks: { color: ink, precision: 0 }
            }
          },
          plugins: {
            tooltip: {
              callbacks: {
                label: function (context) {
                  if (context.dataset.label === 'Revenue') {
                    return 'Revenue: ' + money(context.parsed.y);
                  }
                  return 'Orders: ' + context.parsed.y;
                }
              }
            }
          }
        }
      });
    }

    // --- Orders by status -------------------------------------------------------
    var statusCanvas = canvas('chart-status');

    if (statusCanvas) {
      new Chart(statusCanvas, {
        type: 'doughnut',
        data: {
          labels: data.status.labels,
          datasets: [{
            data: data.status.values,
            backgroundColor: data.status.keys.map(function (key) {
              return statusColours[key] || accent;
            }),
            borderColor: surface,
            borderWidth: 2
          }]
        },
        options: {
          responsive: true,
          cutout: '58%',
          plugins: { legend: { position: 'bottom' } }
        }
      });
    }

    // --- Top ten sellers, laid out horizontally so the names stay readable -----
    var topCanvas = canvas('chart-top');

    if (topCanvas) {
      new Chart(topCanvas, {
        type: 'bar',
        data: {
          labels: data.top.labels,
          datasets: [{
            label: 'Cups sold',
            data: data.top.values,
            backgroundColor: fade(accent, 0.8),
            hoverBackgroundColor: accent,
            borderRadius: 4,
            borderSkipped: false
          }]
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          scales: {
            x: { grid: axis.grid, border: axis.border, beginAtZero: true, ticks: { color: ink, precision: 0 } },
            y: { grid: { display: false }, border: { display: false }, ticks: { color: ink } }
          },
          plugins: { legend: { display: false } }
        }
      });
    }

    // --- Orders by hour of day ---------------------------------------------------
    var hoursCanvas = canvas('chart-hours');

    if (hoursCanvas) {
      new Chart(hoursCanvas, {
        type: 'bar',
        data: {
          labels: data.hours.labels,
          datasets: [{
            label: 'Orders',
            data: data.hours.values,
            backgroundColor: fade(statusColours.preparing, 0.75),
            hoverBackgroundColor: statusColours.preparing,
            borderRadius: 3,
            borderSkipped: false
          }]
        },
        options: {
          responsive: true,
          scales: {
            x: { grid: { display: false }, border: axis.border, ticks: { color: ink, maxRotation: 0, autoSkipPadding: 12 } },
            y: { grid: axis.grid, border: axis.border, beginAtZero: true, ticks: { color: ink, precision: 0 } }
          },
          plugins: { legend: { display: false } }
        }
      });
    }

    // --- SMS usage -----------------------------------------------------------------
    var smsCanvas = canvas('chart-sms');

    if (smsCanvas) {
      new Chart(smsCanvas, {
        type: 'doughnut',
        data: {
          labels: data.sms.labels,
          datasets: [{
            data: data.sms.values,
            backgroundColor: data.sms.keys.map(function (key) {
              return smsColours[key] || accent;
            }),
            borderColor: surface,
            borderWidth: 2
          }]
        },
        options: {
          responsive: true,
          cutout: '58%',
          plugins: { legend: { position: 'bottom' } }
        }
      });
    }
  })();
})();
