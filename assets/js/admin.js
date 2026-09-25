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

  /** Build a sprite reference, e.g. iconHref('icon-eye'). */
  function iconHref(name) {
    return (document.body.getAttribute('data-sprite') || '') + '#' + name;
  }

  /** Read a <script type="application/json"> block by id, or null. */
  function readJson(id) {
    var source = document.getElementById(id);
    if (!source) return null;

    try {
      return JSON.parse(source.textContent);
    } catch (error) {
      return null;
    }
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

  /* --- Password fields ------------------------------------------------------
     Two things: a reveal toggle on every password box, and a live checklist
     on the new-password box. The checklist reads its rules from a JSON block
     the server prints, so it always matches what the server enforces.      */

  (function passwordFields() {
    var boxes = document.querySelectorAll('input[type="password"]');

    boxes.forEach(function (input) {
      if (input.dataset.noReveal === 'true') return;

      var wrap = document.createElement('div');
      wrap.className = 'password-wrap';
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);

      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'password-reveal';
      button.setAttribute('aria-label', 'Show password');
      button.setAttribute('aria-pressed', 'false');
      button.innerHTML =
        '<svg class="icon-sm" aria-hidden="true"><use href="' + iconHref('icon-eye') + '"></use></svg>';

      button.addEventListener('click', function () {
        var showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';

        button.setAttribute('aria-pressed', String(!showing));
        button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        button.innerHTML =
          '<svg class="icon-sm" aria-hidden="true"><use href="'
          + iconHref(showing ? 'icon-eye' : 'icon-eye-off') + '"></use></svg>';

        // Keep the caret where it was, rather than jumping to the start.
        var at = input.value.length;
        input.focus();
        try { input.setSelectionRange(at, at); } catch (e) { /* not all types allow it */ }
      });

      wrap.appendChild(button);
    });

    /* --- Live checklist ----------------------------------------------------- */

    var list = document.querySelector('[data-password-rules]');
    if (!list) return;

    var config = readJson('password-policy');
    if (!config) return;

    var target = document.getElementById(list.getAttribute('data-password-rules'));
    var confirmField = list.getAttribute('data-password-confirm')
      ? document.getElementById(list.getAttribute('data-password-confirm'))
      : null;

    if (!target) return;

    var checks = [
      {
        key: 'length',
        label: 'At least ' + config.min_length + ' characters',
        test: function (v) { return v.length >= config.min_length; }
      },
      {
        key: 'letter',
        label: 'Contains a letter',
        test: function (v) { return /[A-Za-z]/.test(v); }
      },
      {
        key: 'number',
        label: 'Contains a number',
        test: function (v) { return /[0-9]/.test(v); }
      },
      {
        key: 'common',
        label: 'Not an easily guessed word',
        test: function (v) {
          if (v === '') return false;
          var lower = v.toLowerCase();
          return !config.common.some(function (bad) {
            return lower.indexOf(bad) !== -1;
          });
        }
      }
    ];

    if (confirmField) {
      checks.push({
        key: 'match',
        label: 'Both boxes match',
        test: function (v) { return v !== '' && v === confirmField.value; }
      });
    }

    // Build the list once, then only flip classes as they type.
    var items = {};

    checks.forEach(function (check) {
      var li = document.createElement('li');
      li.className = 'rule';
      li.innerHTML =
        '<span class="rule-mark" aria-hidden="true">'
        + '<svg class="icon-sm"><use href="' + iconHref('icon-check') + '"></use></svg>'
        + '</span><span>' + check.label + '</span>';
      list.appendChild(li);
      items[check.key] = li;
    });

    function review() {
      var value = target.value;

      checks.forEach(function (check) {
        var ok = check.test(value);
        var li = items[check.key];

        li.classList.toggle('is-met', ok);
        li.classList.toggle('is-unmet', !ok && value !== '');
        li.setAttribute('aria-label', check.label + (ok ? ': met' : ': not yet met'));
      });
    }

    target.addEventListener('input', review);
    if (confirmField) confirmField.addEventListener('input', review);
    review();
  })();

  /* --- Menu: batch selection and picture preview ---------------------------- */

  (function menuTools() {
    /* Tick boxes on the item list, with a select-all in the header and a bar
       that only appears once something is actually selected. */
    var table = document.querySelector('[data-batch-table]');

    if (table) {
      var all = table.querySelector('[data-batch-all]');
      var bar = document.querySelector('[data-batch-bar]');
      var count = document.querySelector('[data-batch-count]');
      var clear = document.querySelector('[data-batch-clear]');

      var items = function () {
        return Array.prototype.slice.call(table.querySelectorAll('[data-batch-item]'));
      };

      var review = function () {
        var picked = items().filter(function (box) { return box.checked; });

        if (count) count.textContent = String(picked.length);

        if (bar) {
          if (picked.length > 0) {
            bar.removeAttribute('hidden');
          } else {
            bar.setAttribute('hidden', '');
          }
        }

        if (all) {
          all.checked = picked.length > 0 && picked.length === items().length;
          all.indeterminate = picked.length > 0 && picked.length < items().length;
        }

        items().forEach(function (box) {
          var row = box.closest('tr');
          if (row) row.classList.toggle('is-picked', box.checked);
        });
      };

      if (all) {
        all.addEventListener('change', function () {
          items().forEach(function (box) { box.checked = all.checked; });
          review();
        });
      }

      table.addEventListener('change', function (event) {
        if (event.target.hasAttribute('data-batch-item')) review();
      });

      if (clear) {
        clear.addEventListener('click', function () {
          items().forEach(function (box) { box.checked = false; });
          if (all) all.checked = false;
          review();
        });
      }

      review();
    }

    /* Show the chosen picture before it is uploaded, so a wrong file is
       obvious without a round trip to the server. */
    var input = document.querySelector('[data-picture-input]');

    if (input) {
      var preview = document.querySelector('[data-picture-preview]');
      var empty = document.querySelector('[data-picture-empty]');

      input.addEventListener('change', function () {
        var file = input.files && input.files[0];

        if (!file || !preview) return;

        if (!/^image\//.test(file.type)) {
          if (window.OurCoffee) window.OurCoffee.toast('That file is not a picture.', 'error');
          input.value = '';
          return;
        }

        if (file.size > 2 * 1024 * 1024) {
          if (window.OurCoffee) window.OurCoffee.toast('That picture is over the 2 MB limit.', 'error');
          input.value = '';
          return;
        }

        var reader = new FileReader();

        reader.onload = function (event) {
          preview.src = event.target.result;
          preview.removeAttribute('hidden');
          if (empty) empty.setAttribute('hidden', '');
        };

        reader.readAsDataURL(file);
      });
    }
  })();

  /* --- Customisation: switch group without reloading -------------------------
     The choices panel is rendered by the server for whichever group is
     selected, so switching groups needs a round trip. This fetches just that
     and swaps it in, instead of throwing the whole page away and repainting
     it. The links stay real links, so this still works with JS switched off
     and the back button behaves. */

  (function groupSwitcher() {
    var panel = document.getElementById('choices-panel');
    var list = document.querySelector('[data-group-list]') || document;

    if (!panel || !window.fetch || !window.history.pushState) return;

    var busy = false;

    function highlight(groupId) {
      document.querySelectorAll('[data-group-row]').forEach(function (row) {
        row.classList.toggle('row-low', row.getAttribute('data-group-row') === String(groupId));
      });
    }

    function load(url, groupId, push) {
      if (busy) return;
      busy = true;
      panel.classList.add('is-loading');

      fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (response) {
          if (!response.ok) throw new Error('bad response');
          return response.text();
        })
        .then(function (html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var fresh = doc.getElementById('choices-panel');

          // If the shape is not what we expect, fall back to a real navigation
          // rather than leaving a half-updated page.
          if (!fresh) {
            window.location.href = url;
            return;
          }

          panel.innerHTML = fresh.innerHTML;

          // The server says which group it rendered. Trusting that rather
          // than the click means back and forward highlight correctly even
          // with no stored state.
          var shown = fresh.getAttribute('data-selected-group') || groupId;
          panel.setAttribute('data-selected-group', shown);
          highlight(shown);

          if (push) window.history.pushState({ groupId: groupId }, '', url);

          panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        })
        .catch(function () {
          window.location.href = url;
        })
        .finally(function () {
          busy = false;
          panel.classList.remove('is-loading');
        });
    }

    document.addEventListener('click', function (event) {
      var link = event.target.closest('[data-group-link]');
      if (!link) return;

      // Let the browser handle anything that is not a plain left click.
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;

      event.preventDefault();
      load(link.getAttribute('href'), link.getAttribute('data-group-link'), true);
    });

    window.addEventListener('popstate', function (event) {
      var groupId = event.state && event.state.groupId;
      load(window.location.href, groupId, false);
    });
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
