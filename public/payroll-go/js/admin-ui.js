/* PayrollGo V5: progressive enhancement for existing Laravel forms.
   Delegated events work after safe in-app navigation; no custom form submission.
   Server-side validation and payroll calculations remain authoritative. */
(function () {
  'use strict';
  function pesos(amount) {
    return new Intl.NumberFormat('en-PH', {style:'currency', currency:'PHP', minimumFractionDigits:2, maximumFractionDigits:2}).format(amount);
  }
  function asCentavos(input) {
    if (!input || input.value.trim() === '') return 0;
    var raw = input.value.trim();
    if (!/^\d{1,9}(?:\.\d{0,2})?$/.test(raw)) return NaN;
    var parts = raw.split('.');
    return Number(parts[0]) * 100 + Number((parts[1] || '').padEnd(2, '0'));
  }
  function refreshPreview(form) {
    var preview = form.querySelector('[data-pay-preview]');
    if (!preview) return;
    var fields = ['base','overtime','allowances','deductions'].map(function (key) {
      return asCentavos(form.querySelector('[data-pay-field="'+key+'"]'));
    });
    var valid = fields.every(Number.isFinite);
    var gross = valid ? fields[0] + fields[1] + fields[2] : 0;
    var net = gross - (valid ? fields[3] : 0);
    var grossEl = preview.querySelector('[data-pay-gross]');
    var netEl = preview.querySelector('[data-pay-net]');
    if (grossEl) grossEl.textContent = valid ? pesos(gross/100) : 'Check amounts';
    if (netEl) netEl.textContent = valid ? pesos(net/100) : 'Check amounts';
    preview.classList.toggle('is-invalid', !valid || net < 0);
  }
  function init() {
    document.querySelectorAll('[data-pay-preview]').forEach(function (preview) {
      var form = preview.closest('form');
      if (form) refreshPreview(form);
    });
  }
  document.addEventListener('input',function(e) {
    if (!e.target.matches('[data-pay-field]')) return;
    var form=e.target.closest('form');
    if (form) refreshPreview(form);
  });
  document.addEventListener('submit',function(e) {
    var form=e.target;
    if (!form.matches('[data-pro-submit-lock]') || !form.checkValidity()) return;
    var submit=form.querySelector('button[type="submit"]');
    if (!submit || submit.disabled) return;
    // Keep the successful submission in the native Laravel flow.
    // Delay the visual disabled state until after the browser dispatches submit.
    requestAnimationFrame(function() {
      if (!document.contains(form)) return;
      submit.disabled=true;
      submit.setAttribute('aria-busy','true');
      submit.classList.add('pro-is-submitting');
    });
  });
  document.addEventListener('payroll:navigated',init);
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
  else init();
})();
