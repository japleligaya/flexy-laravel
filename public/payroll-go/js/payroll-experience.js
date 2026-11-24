/* Progressive setup guidance. Financial decisions remain on the server. */
(function () {
  'use strict';
  if (window.payrollExperienceMounted) return;
  window.payrollExperienceMounted = true;
  const mounted = new WeakSet();
  function revealHash() {
    const target = document.getElementById(location.hash.slice(1));
    if (!target || !location.hash) return;
    let parent = target;
    while (parent) { if (parent.tagName === 'DETAILS') parent.open = true; parent = parent.parentElement; }
    target.scrollIntoView({block:'start'});
  }
  function mount() {
    revealHash();
    document.querySelectorAll('[data-payroll-wizard]').forEach(form => {
      if (mounted.has(form)) return;
      mounted.add(form);
      const steps = [...form.querySelectorAll('[data-setup-step]')];
      const controls = form.querySelector('[data-wizard-controls]');
      const progress = form.querySelector('[data-wizard-progress]');
      let current = 0;
      form.noValidate = true;
      function timingOptions() {
        const frequency = form.elements.frequency.value;
        const timing = form.elements.contribution_timing;
        [...timing.options].forEach(option => {
          option.disabled = !!option.value && !!frequency && (frequency === 'monthly' ? option.value !== 'monthly' : option.value === 'monthly');
        });
        if (timing.selectedOptions[0]?.disabled) timing.value = '';
      }
      form.elements.frequency.addEventListener('change', timingOptions);
      timingOptions();
      controls.hidden = false;
      function show(index, focus) {
        current = index;
        steps.forEach((step, i) => { step.hidden = i !== index; });
        progress.textContent = `Step ${index + 1} of ${steps.length}: ${steps[index].querySelector('legend').textContent}`;
        controls.querySelector('[data-wizard-back]').hidden = index === 0;
        controls.querySelector('[data-wizard-next]').hidden = index === steps.length - 1;
        form.querySelector('[data-wizard-save]').hidden = index !== steps.length - 1;
        if (focus) { const title = steps[index].querySelector('legend'); title.tabIndex = -1; title.focus(); }
        const summary = form.querySelector('[data-setup-summary]');
        if (index === steps.length - 1) {
          summary.replaceChildren();
          form.querySelectorAll('[data-summary-label]').forEach(input => {
            if (input.type === 'checkbox' && !input.checked) return;
            const item = document.createElement('li');
            const value = input.tagName === 'SELECT' ? input.selectedOptions[0]?.textContent : input.type === 'checkbox' ? input.dataset.summaryValue : input.value;
            item.textContent = `${input.dataset.summaryLabel}: ${value || 'Not provided'}`;
            summary.append(item);
          });
        }
      }
      function valid(index) {
        if (steps[index].querySelector('[name="workdays[]"]') && !form.querySelector('[name="workdays[]"]:checked')) {
          show(index, false);
          const input = steps[index].querySelector('[name="workdays[]"]');
          input.setCustomValidity('Choose at least one normal workday.');
          input.reportValidity();
          input.setCustomValidity('');
          return false;
        }
        for (const input of steps[index].querySelectorAll('input, select, textarea')) {
          if (!input.checkValidity()) { show(index, false); input.reportValidity(); return false; }
        }
        return true;
      }
      controls.querySelector('[data-wizard-next]').addEventListener('click', () => { if (valid(current)) show(current + 1, true); });
      controls.querySelector('[data-wizard-back]').addEventListener('click', () => show(current - 1, true));
      form.addEventListener('submit', event => {
        for (let i = 0; i < steps.length; i++) {
          if (!valid(i)) { event.preventDefault(); return; }
        }
      });
      const errorStep = steps.findIndex(step => step.querySelector('.is-invalid'));
      show(errorStep >= 0 ? errorStep : 0, false);
    });
  }
  document.addEventListener('click', event => {
    const link = event.target.closest('a[data-payroll-fix]');
    if (!link || !link.hash || new URL(link.href).pathname !== location.pathname) return;
    const target = document.getElementById(link.hash.slice(1));
    if (!target) return;
    let parent = target;
    while (parent) { if (parent.tagName === 'DETAILS') parent.open = true; parent = parent.parentElement; }
    const focus = target.querySelector('input:not([type=hidden]),select,summary') || target;
    focus.tabIndex = focus.tabIndex < 0 ? -1 : focus.tabIndex;
    focus.focus({preventScroll:true});
  });
  document.addEventListener('payroll:navigated', mount);
  document.addEventListener('click', event => {
    if (event.target.closest('[data-close-feature-help]')) event.target.closest('[data-feature-help]').open = false;
    document.querySelectorAll('[data-feature-help][open]').forEach(help => { if (!help.contains(event.target)) help.open = false; });
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('[data-feature-help][open]').forEach(help => { help.open = false; help.querySelector('summary').focus(); });
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
