<<<<<<< HEAD
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
=======
(function(){
  const inputs = Array.from(document.querySelectorAll('.score-input'));
  function normalize(val){
    const raw = String(val || '').replace(/[^0-9]/g,'');
    if(raw.length < 2) return val;
    const n = Number(raw) / 100;
    return Number.isFinite(n) ? n.toFixed(2).replace(/\.00$/,'.0').replace(/(\.\d)0$/, '$1') : val;
  }
  function nextInput(current){
    const idx = inputs.indexOf(current);
    if(idx >= 0 && idx < inputs.length-1) inputs[idx+1].focus();
  }
  inputs.forEach((inp)=>{
    inp.addEventListener('blur',()=>{
      const normalized = normalize(inp.value);
      if(normalized !== inp.value) inp.value = normalized;
    });
    inp.addEventListener('input',()=>{
      const max = parseFloat(inp.dataset.max || '0');
      const value = parseFloat(inp.value || '');
      if(!Number.isNaN(value) && value === max){
        nextInput(inp);
>>>>>>> main
      }
    });
  });

<<<<<<< HEAD
  document.querySelectorAll('[data-fill-column]').forEach((button) => {
    button.addEventListener('click', () => {
      const col = button.dataset.fillColumn;
      const max = button.dataset.max;
      document.querySelectorAll('.score-input[data-col="' + col + '"]:not([readonly])').forEach((input) => {
        if (String(input.value).trim() === '') {
          input.value = max;
        }
=======
  document.querySelectorAll('[data-fill-column]').forEach((btn)=>{
    btn.addEventListener('click',()=>{
      const col = btn.dataset.fillColumn;
      const max = btn.dataset.max;
      document.querySelectorAll('.score-input[data-col="'+col+'"]').forEach((input)=>{
        if(String(input.value).trim() === '') input.value = max;
>>>>>>> main
      });
    });
  });
})();
