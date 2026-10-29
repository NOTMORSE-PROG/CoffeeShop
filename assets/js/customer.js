/* ---------------------------------------------------------------------------
   Customer site behaviour.

   Every interaction here has a server-side equivalent, so the site still
   works with JavaScript switched off.
   --------------------------------------------------------------------------- */

(function () {
  'use strict';

  /* --- Mobile navigation ---------------------------------------------------- */

  var toggle = document.querySelector('.nav-toggle');
  var mobileNav = document.getElementById('mobile-nav');

  if (toggle && mobileNav) {
    toggle.addEventListener('click', function () {
      var open = mobileNav.hasAttribute('hidden');

      if (open) {
        mobileNav.removeAttribute('hidden');
      } else {
        mobileNav.setAttribute('hidden', '');
      }

      toggle.setAttribute('aria-expanded', String(open));
    });
  }

  /* --- Quantity stepper -----------------------------------------------------
     Drives a hidden number input so the form still posts a real value.      */

  document.querySelectorAll('[data-qty]').forEach(function (control) {
    var input = control.querySelector('input[type="number"]');
    var output = control.querySelector('output');
    if (!input) return;

    var min = parseInt(input.min, 10) || 1;
    var max = parseInt(input.max, 10) || 20;

    function render() {
      var value = parseInt(input.value, 10) || min;
      if (output) output.textContent = String(value);

      var dec = control.querySelector('[data-qty-down]');
      var inc = control.querySelector('[data-qty-up]');
      if (dec) dec.disabled = value <= min;
      if (inc) inc.disabled = value >= max;
    }

    control.addEventListener('click', function (event) {
      var down = event.target.closest('[data-qty-down]');
      var up = event.target.closest('[data-qty-up]');
      if (!down && !up) return;

      event.preventDefault();

      var value = parseInt(input.value, 10) || min;
      value += up ? 1 : -1;
      input.value = String(Math.max(min, Math.min(max, value)));

      render();
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });

    render();
  });

  /* --- Live price on the product page ---------------------------------------
     Reads prices from data attributes so nothing about money is hard-coded
     in JS. The server prices the order again regardless.                     */

  var priceForm = document.querySelector('[data-price-form]');

  if (priceForm) {
    var priceOut = priceForm.querySelector('[data-price-output]');
    var basePrice = parseFloat(priceForm.getAttribute('data-base-price')) || 0;

    var updatePrice = function () {
      var extra = 0;

      priceForm.querySelectorAll('input[name="option_ids[]"]:checked, input[name^="option_single"]:checked').forEach(function (input) {
        extra += parseFloat(input.getAttribute('data-price-delta')) || 0;
      });

      var qtyInput = priceForm.querySelector('input[name="quantity"]');
      var qty = parseInt(qtyInput && qtyInput.value, 10) || 1;

      if (priceOut && window.OurCoffee) {
        priceOut.textContent = window.OurCoffee.peso((basePrice + extra) * qty);
      }
    };

    priceForm.addEventListener('change', updatePrice);
    updatePrice();
  }

  /* --- Checkout: reveal delivery fields and the GCash panel ------------------ */

  var checkout = document.querySelector('[data-checkout]');

  if (checkout) {
    var deliveryFields = checkout.querySelector('.delivery-fields');
    var addressInput = checkout.querySelector('[name="delivery_address"]');
    // One panel per wallet now, so the whole set is collected and the
    // chosen one shown. Was a single GCash panel.
    var walletPanels = checkout.querySelectorAll('[data-wallet-panel]');

    var syncOrderType = function () {
      var picked = checkout.querySelector('input[name="order_type"]:checked');
      var isDelivery = picked && picked.value === 'delivery';

      var cashChoice = checkout.querySelector('[data-cash-choice]');
      var cashInput = checkout.querySelector('input[name="payment_method"][value="cash"]');
      if (cashChoice) cashChoice.hidden = !isDelivery;
      if (cashInput) {
        cashInput.disabled = !isDelivery;
        if (!isDelivery && cashInput.checked) cashInput.checked = false;
      }
      if (!checkout.querySelector('input[name="payment_method"]:checked')) {
        var firstPayment = checkout.querySelector('input[name="payment_method"]:not(:disabled)');
        if (firstPayment) firstPayment.checked = true;
      }
      syncPayment();

      if (deliveryFields) {
        if (isDelivery) {
          deliveryFields.removeAttribute('hidden');
        } else {
          deliveryFields.setAttribute('hidden', '');
        }
      }

      // Only require an address when it is actually being asked for.
      if (addressInput) addressInput.required = Boolean(isDelivery);
      var deliveryCity = checkout.querySelector('[name="delivery_city"]');
      if (deliveryCity) deliveryCity.required = Boolean(isDelivery);
    };

    var syncPayment = function () {
      var picked = checkout.querySelector('input[name="payment_method"]:checked');
      var chosen = picked ? picked.value : '';

      Array.prototype.forEach.call(walletPanels, function (panel) {
        if (panel.getAttribute('data-wallet-panel') === chosen) {
          panel.removeAttribute('hidden');
        } else {
          panel.setAttribute('hidden', '');
        }
      });
    };

    /* Keep the summary honest as the choice changes. The server prices the
       order again on submit, so this is presentation only. */
    var feeOut = checkout.querySelector('[data-delivery-fee]');
    var totalOut = checkout.querySelector('[data-order-total]');
    var subtotal = parseFloat(checkout.getAttribute('data-subtotal')) || 0;
    var flatFee = parseFloat(checkout.getAttribute('data-flat-fee')) || 0;
    var freeCity = (checkout.getAttribute('data-free-city') || '').trim();
    var cityInput = checkout.querySelector('[name="delivery_city"]');

    var syncTotals = function () {
      var picked = checkout.querySelector('input[name="order_type"]:checked');
      var isDelivery = picked && picked.value === 'delivery';
      var fee = 0;
      var city = ((cityInput && cityInput.value) || '').trim().toLowerCase();

      if (isDelivery && city !== '') {
        fee = (freeCity !== '' && city === freeCity) ? 0 : flatFee;
      }

      if (feeOut) {
        feeOut.textContent = !isDelivery
          ? '—'
          : (city === '' ? 'Choose a city' : (fee <= 0 ? 'Free' : window.OurCoffee.peso(fee)));
      }

      if (totalOut) {
        totalOut.textContent = window.OurCoffee.peso(subtotal + fee);
      }
    };

    checkout.addEventListener('change', function (event) {
      if (event.target.name === 'order_type') syncOrderType();
      if (event.target.name === 'payment_method') syncPayment();
      syncTotals();
    });

    checkout.addEventListener('input', function (event) {
      if (event.target.name === 'delivery_city') syncTotals();
    });

    syncOrderType();
    syncPayment();
    syncTotals();
  }

  /* --- Order tracking: refresh the status without a reload ------------------- */

  var tracker = document.querySelector('[data-track-poll]');

  if (tracker && window.OurCoffee) {
    var pollUrl = tracker.getAttribute('data-track-poll');
    var lastStatus = tracker.getAttribute('data-current-status');

    window.OurCoffee.poll(pollUrl, 20000, function (data) {
      if (!data || !data.status || data.status === lastStatus) return;

      // The status moved on, so show the new state properly rendered.
      window.location.reload();
    });
  }

  /* --- The payment QR, big enough to point a camera at -------------------------
     128px is fine as a thumbnail and useless as a QR code, particularly on the
     phone the customer is holding. The thumbnail is a link to the picture, so
     with no script it opens full size the ordinary way; here it opens in a
     dialog instead, which keeps the checkout form filled in behind it.      */

  (function qrZoom() {
    if (!window.HTMLDialogElement) return;

    var dialog = null;
    var image = null;
    var caption = null;

    function build() {
      dialog = document.createElement('dialog');
      dialog.className = 'modal qr-dialog';

      var card = document.createElement('div');
      card.className = 'modal-card';

      var close = document.createElement('button');
      close.type = 'button';
      close.className = 'modal-close';
      close.setAttribute('aria-label', 'Close');
      close.textContent = '\u00d7';
      close.addEventListener('click', function () { dialog.close(); });

      caption = document.createElement('h2');
      caption.className = 'modal-title';

      image = document.createElement('img');
      image.className = 'qr-dialog-image';
      image.alt = '';

      card.appendChild(close);
      card.appendChild(caption);
      card.appendChild(image);
      dialog.appendChild(card);
      document.body.appendChild(dialog);

      dialog.addEventListener('click', function (event) {
        if (event.target === dialog) dialog.close();
      });
    }

    document.addEventListener('click', function (event) {
      var link = event.target.closest('[data-qr-zoom]');
      if (!link) return;

      // Leave the browser's own shortcuts alone.
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

      event.preventDefault();

      if (!dialog) build();

      var label = link.getAttribute('data-qr-zoom') || 'Payment';
      caption.textContent = 'Scan with ' + label;
      image.alt = label + ' QR code for the shop';
      image.src = link.getAttribute('href');

      dialog.showModal();
    });
  })();

  /* --- Floating Customer Service button toggle ------------------------------- */
  var fabBtn = document.getElementById('cs-fab-btn');
  var fabPopover = document.getElementById('cs-fab-popover');
  var fabClose = document.getElementById('cs-fab-close');

  if (fabBtn && fabPopover) {
    var togglePopover = function (open) {
      if (typeof open !== 'boolean') {
        open = fabPopover.hasAttribute('hidden');
      }
      if (open) {
        fabPopover.removeAttribute('hidden');
      } else {
        fabPopover.setAttribute('hidden', '');
      }
      fabBtn.setAttribute('aria-expanded', String(open));
    };

    fabBtn.addEventListener('click', function (event) {
      event.preventDefault();
      togglePopover();
    });

    if (fabClose) {
      fabClose.addEventListener('click', function () {
        togglePopover(false);
      });
    }

    document.addEventListener('click', function (event) {
      if (fabPopover.hasAttribute('hidden')) return;
      var container = fabBtn.closest('.cs-fab-container');
      if (container && !container.contains(event.target)) {
        togglePopover(false);
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !fabPopover.hasAttribute('hidden')) {
        togglePopover(false);
        fabBtn.focus();
      }
    });
  }

})();
