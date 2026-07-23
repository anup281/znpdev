(() => {
  const root = document.querySelector('[data-model-settings]');
  if (!root) return;
  const timers = new WeakMap();
  const sequences = new WeakMap();
  async function save(input) {
    timers.delete(input);
    const status = input.closest('.admin-model-setting').querySelector('[data-save-status]');
    const sequence = (sequences.get(input) || 0) + 1;
    sequences.set(input, sequence);
    status.textContent = 'Saving…'; status.className = 'admin-model-save-status saving';
    const data = new FormData();
    data.set('csrf_token', root.dataset.csrf); data.set('investment_opportunity_id', root.dataset.projectId); data.set('field', input.name); data.set('value', input.value.trim());
    try {
      const response = await fetch('model_settings_autosave.php', {method:'POST',body:data,credentials:'same-origin'});
      const payload = await response.json();
      if (sequences.get(input) !== sequence) return;
      if (!response.ok || !payload.ok) throw new Error(payload.error || 'The setting could not be saved.');
      status.textContent = 'Saved'; status.className = 'admin-model-save-status saved';
      if (window.adminToast) window.adminToast('Model setting saved.', 'success');
    } catch (error) {
      if (sequences.get(input) !== sequence) return;
      status.textContent = error.message || 'Save failed'; status.className = 'admin-model-save-status error';
    }
  }
  root.querySelectorAll('input[data-autosave]').forEach((input) => {
    input.addEventListener('input', () => {
      clearTimeout(timers.get(input));
      const status = input.closest('.admin-model-setting').querySelector('[data-save-status]');
      status.textContent = 'Unsaved'; status.className = 'admin-model-save-status';
      timers.set(input, setTimeout(() => save(input), 550));
      const pairs = {tier1_lp_split:'tier1_gp_split',tier1_gp_split:'tier1_lp_split',tier2_lp_split:'tier2_gp_split',tier2_gp_split:'tier2_lp_split'};
      const partner = root.querySelector('[name="' + (pairs[input.name] || '') + '"]');
      if (partner) {
        partner.value = Math.max(0, 100 - Math.min(100, Number(input.value) || 0)).toFixed(2);
        clearTimeout(timers.get(partner));
        const partnerStatus = partner.closest('.admin-model-setting').querySelector('[data-save-status]');
        partnerStatus.textContent = 'Unsaved'; partnerStatus.className = 'admin-model-save-status';
        timers.set(partner, setTimeout(() => save(partner), 550));
      }
    });
    input.addEventListener('blur', () => { if (!timers.has(input)) return; clearTimeout(timers.get(input)); save(input); });
  });
})();
