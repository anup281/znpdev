(() => {
  const fields = document.querySelectorAll('.admin-autosave-notes');

  fields.forEach((field) => {
    const panel = field.closest('.admin-notes-panel');
    const status = panel?.querySelector('[data-note-status]');
    let timer = null;
    let lastSavedValue = field.value;

    const setStatus = (text, state = '') => {
      if (!status) return;
      status.textContent = text;
      status.className = `admin-note-status ${state}`.trim();
    };

    const save = async () => {
      if (field.value === lastSavedValue) {
        setStatus('Saved', 'is-saved');
        return;
      }

      setStatus('Saving…', 'is-saving');

      const body = new URLSearchParams({
        csrf_token: field.dataset.csrfToken || '',
        type: field.dataset.noteType || '',
        record_id: field.dataset.recordId || '',
        notes: field.value,
      });

      try {
        const response = await fetch('save_notes.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: body.toString(),
          credentials: 'same-origin',
        });

        const result = await response.json();

        if (!response.ok || !result.ok) {
          throw new Error(result.message || 'Save failed');
        }

        lastSavedValue = field.value;
        setStatus(`Saved ${result.saved_at || ''}`.trim(), 'is-saved');
      } catch (error) {
        setStatus('Not saved — try again', 'is-error');
      }
    };

    field.addEventListener('input', () => {
      window.clearTimeout(timer);
      setStatus('Unsaved changes', 'is-unsaved');
      timer = window.setTimeout(save, 700);
    });

    field.addEventListener('blur', () => {
      window.clearTimeout(timer);
      save();
    });
  });
})();
