(function () {
  'use strict';

  document.querySelectorAll('[data-project-team-note]').forEach(function (field) {
    var status = field.closest('.project-team-note-editor').querySelector('[data-note-status]');
    var timer = null;
    var controller = null;
    var lastSavedValue = field.value;

    function setStatus(message, state) {
      status.textContent = message;
      status.classList.remove('is-saving', 'is-saved', 'is-error');
      if (state) status.classList.add(state);
    }

    function save() {
      clearTimeout(timer);
      if (field.value === lastSavedValue) return;
      if (controller) controller.abort();
      controller = new AbortController();
      var valueBeingSaved = field.value;
      var body = new URLSearchParams({
        csrf: field.dataset.csrf,
        project_id: field.dataset.projectId,
        assignment_id: field.dataset.assignmentId,
        notes: valueBeingSaved
      });

      setStatus('Saving…', 'is-saving');
      fetch(field.dataset.endpoint, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
        body: body.toString(),
        signal: controller.signal
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || !data.ok) throw new Error(data.message || 'Notes could not be saved.');
          return data;
        });
      }).then(function (data) {
        lastSavedValue = valueBeingSaved;
        setStatus('Saved ' + data.saved_at, 'is-saved');
        if (field.value !== lastSavedValue) scheduleSave();
      }).catch(function (error) {
        if (error.name !== 'AbortError') setStatus('Not saved — try again', 'is-error');
      });
    }

    function scheduleSave() {
      clearTimeout(timer);
      setStatus('Unsaved changes', '');
      timer = setTimeout(save, 700);
    }

    field.addEventListener('input', scheduleSave);
    field.addEventListener('blur', save);
  });
})();
