(function () {
  const inputs = Array.from(document.querySelectorAll('.score-input:not([readonly])'));

  function formatSmartValue(raw, max) {
    const text = String(raw ?? '').trim();
    if (text === '') return '';
    if (text.includes('.')) return text;
    if (!/^\d+$/.test(text)) return text;

    const integerValue = Number(text);
    if (Number.isFinite(integerValue) && integerValue <= max) {
      return text;
    }

    if (text.length === 1) return text;
    if (text.length === 2) return text[0] + '.' + text[1];
    return text[0] + '.' + text.slice(1);
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
      const max = parseFloat(input.dataset.max || '0');
      const normalized = formatSmartValue(input.value, max);
      if (normalized !== input.value) {
        input.value = normalized;
      }
    });

    input.addEventListener('keyup', () => {
      const raw = String(input.value || '').trim();
      const max = parseFloat(input.dataset.max || '0');

      if (/^\d$/.test(raw) && max <= 7) {
        if (raw === String(Math.trunc(max))) {
          input.value = '';
        }
        nextInput(input);
        return;
      }

      const maybe = parseFloat(formatSmartValue(raw, max));
      if (!Number.isNaN(maybe) && maybe === max) {
        nextInput(input);
      }
    });

    input.addEventListener('change', () => {
      const max = parseFloat(input.dataset.max || '0');
      input.value = formatSmartValue(input.value, max);
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
