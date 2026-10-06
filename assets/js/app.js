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
     destructive admin actions such as cancelling an order.

     The browser's own confirm box cannot be styled, names the host in its
     title bar and reads like a security warning, so this builds the question
     as part of the shop instead. It is a <dialog>, which means focus
     trapping, Esc and the backdrop are the browser's job rather than ours.

     Optional attributes on the trigger:
       data-confirm-title   heading, default "Please confirm"
       data-confirm-action  label on the go-ahead button, default "Confirm"
     A trigger already styled as destructive (btn-danger, btn-danger-text)
     gets a destructive button here too, so the 13 existing call sites did not
     need touching.

     With JavaScript off there is no dialog and no interception: the button is
     a real submit and the server still asks for a CSRF token, which is the
     same way the rest of the page degrades.                                  */

  var confirmDialog = null;

  function buildConfirmDialog() {
    var dialog = document.createElement('dialog');
    dialog.className = 'modal';

    // method="dialog" means each button closes the dialog and reports itself
    // in returnValue, so there is no close handler to keep in step.
    var form = document.createElement('form');
    form.method = 'dialog';
    form.className = 'modal-card';

    var title = document.createElement('h2');
    title.className = 'modal-title';

    // Visible way out, for a phone with no Esc key and for anyone who does
    // not know the backdrop can be clicked.
    var close = document.createElement('button');
    close.type = 'submit';
    close.value = 'cancel';
    close.className = 'modal-close';
    close.setAttribute('aria-label', 'Close');
    close.textContent = '\u00d7';

    var body = document.createElement('p');
    body.className = 'modal-body';

    var actions = document.createElement('div');
    actions.className = 'modal-actions';

    var cancel = document.createElement('button');
    cancel.type = 'submit';
    cancel.value = 'cancel';
    cancel.className = 'btn btn-secondary';
    cancel.textContent = 'Cancel';

    var go = document.createElement('button');
    go.type = 'submit';
    go.value = 'confirm';
    go.className = 'btn';

    actions.appendChild(cancel);
    actions.appendChild(go);
    form.appendChild(close);
    form.appendChild(title);
    form.appendChild(body);
    form.appendChild(actions);
    dialog.appendChild(form);
    document.body.appendChild(dialog);

    // Clicking the backdrop is a cancel. The card covers the middle, so a
    // click landing on the dialog itself came from outside it.
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) {
        dialog.close('cancel');
      }
    });

    return { dialog: dialog, title: title, body: body, go: go, cancel: cancel };
  }

  function askConfirm(trigger, message) {
    // No <dialog> support, or no body to attach to: fall back rather than
    // letting a destructive action through unasked.
    if (!window.HTMLDialogElement || !document.body) {
      return Promise.resolve(window.confirm(message));
    }

    if (!confirmDialog) confirmDialog = buildConfirmDialog();

    var parts = confirmDialog;
    var danger = /btn-danger/.test(trigger.className || '');

    parts.title.textContent = trigger.getAttribute('data-confirm-title') || 'Please confirm';
    parts.body.textContent = message;
    parts.go.textContent = trigger.getAttribute('data-confirm-action')
      || (danger ? 'Delete' : 'Confirm');
    parts.go.className = danger ? 'btn btn-danger' : 'btn';

    return new Promise(function (resolve) {
      parts.dialog.addEventListener('close', function once() {
        parts.dialog.removeEventListener('close', once);
        resolve(parts.dialog.returnValue === 'confirm');
      });

      parts.dialog.returnValue = 'cancel';
      parts.dialog.showModal();

      // Opening on Cancel: the answer to "shall I delete this" should take a
      // deliberate move, not an stray press of Enter.
      parts.cancel.focus();
    });
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-confirm]');
    if (!trigger) return;

    // The replayed click after a yes. Clear the mark and let it through.
    if (trigger.getAttribute('data-confirmed') === 'yes') {
      trigger.removeAttribute('data-confirmed');
      return;
    }

    event.preventDefault();
    event.stopPropagation();

    askConfirm(trigger, trigger.getAttribute('data-confirm')).then(function (ok) {
      if (!ok) return;

      // Replay the click rather than submitting the form directly: a submit
      // button carries its own name and value to the server, and most of
      // these say which action was asked for.
      trigger.setAttribute('data-confirmed', 'yes');
      trigger.click();
    });
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
