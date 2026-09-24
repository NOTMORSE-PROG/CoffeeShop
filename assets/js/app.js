/* ---------------------------------------------------------------------------
   Shared front-end behaviour for both sites.

   No framework and no build step, to match the stack in Chapter 3. Everything
   here degrades safely: if JavaScript is unavailable the pages still work,
   because every action is a real form post to the server.
   --------------------------------------------------------------------------- */

(function () {
  'use strict';

  /* --- Loading splash -------------------------------------------------------
     Hidden once the page has painted, then removed from the DOM so it can
     never sit in the tab order or cover the page if a transition is skipped. */

  function dismissLoader() {
    var loader = document.getElementById('page-loader');
    if (!loader) return;

    loader.classList.add('is-hidden');
    loader.setAttribute('aria-hidden', 'true');

    window.setTimeout(function () {
      if (loader.parentNode) loader.parentNode.removeChild(loader);
    }, 450);
  }

  if (document.readyState === 'complete') {
    dismissLoader();
  } else {
    window.addEventListener('load', function () {
      // A brief hold so the splash does not flash on a fast local load.
      window.setTimeout(dismissLoader, 260);
    });
  }

  // Never let a stuck asset leave the splash up for good.
  window.setTimeout(dismissLoader, 4000);

  /* --- Toasts ---------------------------------------------------------------- */

  function toastRegion() {
    var region = document.querySelector('.toast-region');

    if (!region) {
      region = document.createElement('div');
      region.className = 'toast-region';
      region.setAttribute('role', 'status');
      region.setAttribute('aria-live', 'polite');
      document.body.appendChild(region);
    }

    return region;
  }

  function toast(message, variant) {
    var el = document.createElement('div');
    el.className = 'toast' + (variant ? ' toast-' + variant : '');
    el.textContent = message;

    toastRegion().appendChild(el);

    window.setTimeout(function () {
      el.style.opacity = '0';
      window.setTimeout(function () {
        if (el.parentNode) el.parentNode.removeChild(el);
      }, 220);
    }, 3200);
  }

  /* --- Confirmation ----------------------------------------------------------
     Any element with data-confirm asks before its action runs. Used on
     destructive admin actions such as cancelling an order.                    */

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-confirm]');
    if (!trigger) return;

    if (!window.confirm(trigger.getAttribute('data-confirm'))) {
      event.preventDefault();
      event.stopPropagation();
    }
  });

  /* --- Double submit guard ---------------------------------------------------
     Stops a second order being placed when an impatient tap hits the button
     twice. The button is re-enabled if the page is restored from cache.      */

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.hasAttribute('data-no-guard')) return;

    var button = form.querySelector('[type="submit"]');
    if (!button || button.disabled) return;

    var label = button.getAttribute('data-busy-label');

    window.setTimeout(function () {
      button.disabled = true;
      if (label) {
        button.innerHTML = '<span class="spinner"></span> ' + label;
      }
    }, 0);
  });

  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) return;

    document.querySelectorAll('[type="submit"][disabled]').forEach(function (button) {
      button.disabled = false;
    });
  });

  /* --- Auto-dismissing alerts --------------------------------------------------- */

  document.querySelectorAll('[data-autodismiss]').forEach(function (el) {
    var delay = parseInt(el.getAttribute('data-autodismiss'), 10) || 6000;

    window.setTimeout(function () {
      el.style.transition = 'opacity 300ms ease';
      el.style.opacity = '0';
      window.setTimeout(function () {
        if (el.parentNode) el.parentNode.removeChild(el);
      }, 320);
    }, delay);
  });

  /* --- Live polling helper ------------------------------------------------------
     Used by the admin order queue and the customer order status page. Polling
     pauses while the tab is hidden so a forgotten tab does not keep hitting
     the server all day.                                                        */

  function poll(url, intervalMs, onData) {
    var timer = null;
    var stopped = false;

    function tick() {
      if (stopped || document.hidden) return schedule();

      fetch(url, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
        .then(function (response) {
          return response.ok ? response.json() : null;
        })
        .then(function (data) {
          if (data) onData(data);
        })
        .catch(function () {
          /* A dropped poll is not worth interrupting the user over. */
        })
        .finally(schedule);
    }

    function schedule() {
      if (stopped) return;
      timer = window.setTimeout(tick, intervalMs);
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && !stopped) {
        window.clearTimeout(timer);
        tick();
      }
    });

    schedule();

    return function stop() {
      stopped = true;
      window.clearTimeout(timer);
    };
  }

  /* --- Peso formatting ----------------------------------------------------------- */

  function peso(amount) {
    return '₱' + Number(amount).toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  window.OurCoffee = {
    toast: toast,
    poll: poll,
    peso: peso,
    dismissLoader: dismissLoader
  };
})();
