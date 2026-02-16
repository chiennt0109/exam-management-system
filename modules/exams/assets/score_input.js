(function () {
  const inputs = Array.from(document.querySelectorAll('.score-input:not([readonly])'));

  function toSmartValue(raw) {
    const text = String(raw ?? '').trim();
    if (text === '') return '';
    if (text.includes('.')) return text;
    if (!/^\d+$/.test(text)) return text;

    if (text.length === 1) return text;
    if (text.length === 2) return text[0] + '.' + text[1];
    if (text.length === 3) return text[0] + '.' + text[1] + text[2];
    return text;
  }

  function nextInput(current) {
    const idx = inputs.indexOf(current);
    if (idx >= 0 && idx < inputs.length - 1) {
      inputs[idx + 1].focus();
      inputs[idx + 1].select();
    }
  }

  inputs.forEach((input) => {
    input.addEventListener('blur', () => {
      const normalized = toSmartValue(input.value);
      if (normalized !== input.value) {
        input.value = normalized;
      }
    });

    input.addEventListener('input', () => {
      const raw = String(input.value || '').trim();
      const max = parseFloat(input.dataset.max || '0');

      if (/^\d$/.test(raw) && max <= 7) {
        nextInput(input);
        return;
      }

      const maybe = parseFloat(toSmartValue(raw));
      if (!Number.isNaN(maybe) && maybe === max) {
        nextInput(input);
      }
    });
  });

  document.querySelectorAll('[data-fill-column]').forEach((button) => {
    button.addEventListener('click', () => {
      const col = button.dataset.fillColumn;
      const max = button.dataset.max;
      document.querySelectorAll('.score-input[data-col="' + col + '"]:not([readonly])').forEach((input) => {
        if (String(input.value).trim() === '') {
          input.value = max;
        }
      });
    });
  });
})();
