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
      }
    });
  });

  document.querySelectorAll('[data-fill-column]').forEach((btn)=>{
    btn.addEventListener('click',()=>{
      const col = btn.dataset.fillColumn;
      const max = btn.dataset.max;
      document.querySelectorAll('.score-input[data-col="'+col+'"]').forEach((input)=>{
        if(String(input.value).trim() === '') input.value = max;
      });
    });
  });
})();
