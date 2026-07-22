document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.querySelector('.admin-menu-toggle');
  const nav = document.getElementById('admin-nav');
  const groups = Array.from(document.querySelectorAll('.admin-hover-group'));

  if (toggle && nav) {
    toggle.addEventListener('click', () => {
      const isOpen = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!isOpen));
      nav.classList.toggle('is-open', !isOpen);
    });

    nav.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        if (window.matchMedia('(max-width: 900px)').matches) {
          toggle.setAttribute('aria-expanded', 'false');
          nav.classList.remove('is-open');
        }
      });
    });
  }

  const closeAll = (except = null) => {
    groups.forEach((group) => {
      if (group !== except) {
        group.classList.remove('menu-open');
        const button = group.querySelector(':scope > button');
        if (button) button.setAttribute('aria-expanded', 'false');
      }
    });
  };

  groups.forEach((group) => {
    const button = group.querySelector(':scope > button');
    const menu = group.querySelector(':scope > .admin-hover-menu');
    if (!button || !menu) return;

    button.setAttribute('aria-haspopup', 'true');
    button.setAttribute('aria-expanded', 'false');

    button.addEventListener('click', (event) => {
      if (window.matchMedia('(max-width: 900px)').matches) return;
      event.preventDefault();
      event.stopPropagation();
      const willOpen = !group.classList.contains('menu-open');
      closeAll(group);
      group.classList.toggle('menu-open', willOpen);
      button.setAttribute('aria-expanded', String(willOpen));
    });

    group.addEventListener('mouseenter', () => {
      if (!window.matchMedia('(max-width: 900px)').matches) {
        closeAll(group);
        group.classList.add('menu-open');
        button.setAttribute('aria-expanded', 'true');
      }
    });

    group.addEventListener('mouseleave', () => {
      if (!window.matchMedia('(max-width: 900px)').matches) {
        group.classList.remove('menu-open');
        button.setAttribute('aria-expanded', 'false');
      }
    });
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('.admin-hover-group')) closeAll();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeAll();
  });
});
