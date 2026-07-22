(function () {
  'use strict';

  function toast(message, type) {
    if (!message) return;
    var tray = document.getElementById('admin-toast-tray');
    if (!tray) return;
    var item = document.createElement('div');
    item.className = 'admin-toast admin-toast--' + (type || 'success');
    item.setAttribute('role', type === 'error' ? 'alert' : 'status');
    var text = document.createElement('span');
    text.textContent = message.trim();
    var close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.innerHTML = '&times;';
    close.addEventListener('click', function () { item.remove(); });
    item.appendChild(text);
    item.appendChild(close);
    tray.appendChild(item);
    window.setTimeout(function () {
      item.classList.add('admin-toast--leaving');
      window.setTimeout(function () { item.remove(); }, 250);
    }, type === 'error' ? 8000 : 4500);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.admin-main .status.success, .admin-main .status.error, .admin-main .status.warning').forEach(function (node) {
      var type = node.classList.contains('error') ? 'error' : (node.classList.contains('warning') ? 'warning' : 'success');
      toast(node.textContent, type);
      node.remove();
    });
  });

  window.adminToast = toast;
})();
