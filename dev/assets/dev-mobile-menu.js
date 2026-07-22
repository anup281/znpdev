(function () {
  'use strict';

  function initializeConstructionMenu() {
    var button = document.querySelector('[data-dev-menu-toggle]');
    var nav = document.getElementById('devPrimaryNav');

    if (!button || !nav) {
      return;
    }

    function isMobile() {
      return window.matchMedia('(max-width: 900px)').matches;
    }

    function isOpen() {
      return nav.getAttribute('data-mobile-open') === 'true';
    }

    function setOpen(open) {
      open = Boolean(open && isMobile());

      nav.setAttribute('data-mobile-open', open ? 'true' : 'false');
      nav.classList.toggle('open', open);
      nav.classList.toggle('is-open', open);
      button.classList.toggle('is-open', open);
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      button.setAttribute('aria-label', open ? 'Close construction menu' : 'Open construction menu');
      document.body.classList.toggle('dev-mobile-menu-open', open);
    }

    button.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopImmediatePropagation();
      setOpen(!isOpen());
    }, true);

    nav.addEventListener('click', function (event) {
      var link = event.target.closest ? event.target.closest('a') : null;
      if (link && isMobile()) {
        setOpen(false);
      }
    });

    document.addEventListener('click', function (event) {
      if (isOpen() && !nav.contains(event.target) && !button.contains(event.target)) {
        setOpen(false);
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && isOpen()) {
        setOpen(false);
        button.focus();
      }
    });

    window.addEventListener('resize', function () {
      if (!isMobile()) {
        setOpen(false);
      }
    });

    setOpen(false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeConstructionMenu, { once: true });
  } else {
    initializeConstructionMenu();
  }
}());
