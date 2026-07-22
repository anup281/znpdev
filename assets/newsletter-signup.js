(function () {
  'use strict';

  function showToast(message, type) {
    var tray = document.getElementById('public-toast-tray');
    if (!tray || !message) return;
    var item = document.createElement('div');
    item.className = 'public-toast public-toast--' + (type || 'success');
    item.setAttribute('role', type === 'error' ? 'alert' : 'status');

    var text = document.createElement('span');
    text.textContent = message;
    var close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.innerHTML = '&times;';
    close.addEventListener('click', function () { item.remove(); });

    item.appendChild(text);
    item.appendChild(close);
    tray.appendChild(item);
    window.setTimeout(function () {
      item.classList.add('public-toast--leaving');
      window.setTimeout(function () { item.remove(); }, 250);
    }, type === 'error' ? 7000 : 4500);
  }

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('newsletter-signup-form');
    if (!form) return;
    var button = form.querySelector('button[type="submit"]');
    var email = form.querySelector('input[name="email"]');

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var value = (email.value || '').trim();
      if (!value || !email.checkValidity()) {
        showToast('Please enter a valid email address.', 'error');
        email.focus();
        return;
      }

      var originalText = button.textContent;
      button.disabled = true;
      button.textContent = 'Subscribing…';

      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      }).then(function (response) {
        return response.json().catch(function () {
          return { ok: false, message: 'We could not complete your subscription right now.' };
        }).then(function (data) {
          if (!response.ok && data.ok !== true) throw data;
          return data;
        });
      }).then(function (data) {
        showToast(data.message || 'Thank you for subscribing!', 'success');
        if (data.code !== 'already_subscribed') form.reset();
      }).catch(function (error) {
        showToast(error && error.message ? error.message : 'We could not complete your subscription right now.', 'error');
      }).finally(function () {
        button.disabled = false;
        button.textContent = originalText;
      });
    });
  });
})();
