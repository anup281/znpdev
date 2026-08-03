document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-znp-dashboard-greeting]').forEach((greeting) => {
    const clock = greeting.querySelector('[data-znp-dashboard-time]');
    const date = greeting.querySelector('[data-znp-dashboard-date]');
    if (!clock || !date) return;

    const timeZone = greeting.dataset.timezone || 'America/Chicago';
    const update = () => {
      const now = new Date();
      clock.textContent = new Intl.DateTimeFormat('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        timeZone
      }).format(now);
      date.textContent = new Intl.DateTimeFormat('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
        timeZone
      }).format(now);
    };

    update();
    window.setInterval(update, 1000);
  });
});
