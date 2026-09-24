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
    var gcashPanel = checkout.querySelector('[data-gcash-panel]');

    var syncOrderType = function () {
      var picked = checkout.querySelector('input[name="order_type"]:checked');
      var isDelivery = picked && picked.value === 'delivery';

      if (deliveryFields) {
        if (isDelivery) {
          deliveryFields.removeAttribute('hidden');
        } else {
          deliveryFields.setAttribute('hidden', '');
        }
      }

      // Only require an address when it is actually being asked for.
      if (addressInput) addressInput.required = Boolean(isDelivery);
    };

    var syncPayment = function () {
      var picked = checkout.querySelector('input[name="payment_method"]:checked');
      if (!gcashPanel) return;

      if (picked && picked.value === 'gcash') {
        gcashPanel.removeAttribute('hidden');
      } else {
        gcashPanel.setAttribute('hidden', '');
      }
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

      if (isDelivery) {
        var city = ((cityInput && cityInput.value) || '').trim().toLowerCase();
        fee = (freeCity !== '' && city === freeCity) ? 0 : flatFee;
      }

      if (feeOut) {
        feeOut.textContent = !isDelivery
          ? '—'
          : (fee <= 0 ? 'Free' : window.OurCoffee.peso(fee));
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
})();
