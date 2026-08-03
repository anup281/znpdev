(function () {
  'use strict';

  function isHeic(file) {
    var name = (file && file.name ? file.name : '').toLowerCase();
    var type = (file && file.type ? file.type : '').toLowerCase();
    return type === 'image/heic' || type === 'image/heif' || /\.(heic|heif)$/i.test(name);
  }

  function jpegName(name) {
    var clean = (name || 'photo').replace(/\.(heic|heif)$/i, '');
    return clean + '.jpg';
  }

  function setStatus(form, message, isError) {
    var node = form.querySelector('[data-heic-status]');
    if (!node) return;
    node.textContent = message || '';
    node.classList.toggle('is-error', Boolean(isError));
  }

  async function convertFile(file) {
    if (!isHeic(file)) return file;
    if (typeof window.heic2any !== 'function') {
      throw new Error('The HEIC converter could not load. Check your internet connection and try again, or change the iPhone photo format to Most Compatible.');
    }

    var output = await window.heic2any({
      blob: file,
      toType: 'image/jpeg',
      quality: 0.88
    });

    if (Array.isArray(output)) output = output[0];
    if (!(output instanceof Blob)) throw new Error('This HEIC photo could not be converted.');
    return new File([output], jpegName(file.name), {
      type: 'image/jpeg',
      lastModified: file.lastModified || Date.now()
    });
  }

  async function prepareInput(input, form) {
    var files = Array.prototype.slice.call(input.files || []);
    if (!files.some(isHeic)) return false;

    setStatus(form, 'Converting HEIC photos to JPEG…', false);
    var converted = [];
    for (var i = 0; i < files.length; i += 1) {
      setStatus(form, 'Converting photo ' + (i + 1) + ' of ' + files.length + '…', false);
      converted.push(await convertFile(files[i]));
    }

    var transfer = new DataTransfer();
    converted.forEach(function (file) { transfer.items.add(file); });
    input.files = transfer.files;
    setStatus(form, 'HEIC conversion complete. Uploading JPEG photos…', false);
    return true;
  }

  document.addEventListener('submit', function (event) {
    var form = event.target.closest && event.target.closest('[data-heic-upload-form]');
    if (!form || form.dataset.heicReady === '1' || form.dataset.heicBusy === '1') return;

    var inputs = Array.prototype.slice.call(form.querySelectorAll('[data-heic-input]'));
    var needsConversion = inputs.some(function (input) {
      return Array.prototype.slice.call(input.files || []).some(isHeic);
    });
    if (!needsConversion) return;

    event.preventDefault();
    form.dataset.heicBusy = '1';
    var submit = form.querySelector('button[type="submit"], button:not([type])');
    if (submit) {
      submit.disabled = true;
      submit.dataset.originalText = submit.textContent;
      submit.textContent = 'Preparing Photos…';
    }

    (async function () {
      try {
        for (var i = 0; i < inputs.length; i += 1) await prepareInput(inputs[i], form);
        form.dataset.heicReady = '1';
        form.dataset.heicBusy = '0';
        if (submit) {
          submit.disabled = false;
          submit.textContent = submit.dataset.originalText || 'Submit';
        }
        if (typeof form.requestSubmit === 'function') form.requestSubmit(submit || undefined);
        else form.submit();
      } catch (error) {
        form.dataset.heicBusy = '0';
        setStatus(form, error && error.message ? error.message : 'The HEIC photo could not be converted.', true);
        if (submit) {
          submit.disabled = false;
          submit.textContent = submit.dataset.originalText || 'Submit';
        }
      }
    }());
  }, true);
}());
