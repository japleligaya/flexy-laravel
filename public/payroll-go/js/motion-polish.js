/* PayrollGo V7.5 — progressive pointer/keyboard feedback.
   Delegated listeners survive existing fetch-based admin navigation.
   No event.preventDefault(), navigation delay, or form interception. */
(function () {
  'use strict';
  if (window.__payrollMotionV75) return;
  window.__payrollMotionV75 = true;

  var scope = '.pro-landing, .pro-login-body, .payroll-app';
  var clickable = 'button, .btn, a.pl-button, .pro-primary-btn, .pro-quick-add, .pro-top-icon, .pl-rail-item, .pro-public-link, .page-link';
  var noMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  function targetOf(event) {
    var node = event.target;
    if (!node || !node.closest) return null;
    var control = node.closest(clickable);
    if (!control || !control.closest(scope)) return null;
    if (control.matches(':disabled, [disabled], [aria-disabled="true"], .disabled, [data-no-motion]')) return null;
    return control;
  }
  function wave(control, clientX, clientY) {
    if (noMotion.matches) return;
    // No extra decoration for <input> buttons, non-visible or huge containers.
    if (control.tagName === 'INPUT') return;
    var rect = control.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0 || rect.width > 620 || rect.height > 180) return;
    var x = Math.min(rect.width, Math.max(0, clientX - rect.left));
    var y = Math.min(rect.height, Math.max(0, clientY - rect.top));
    var farX = Math.max(x, rect.width - x);
    var farY = Math.max(y, rect.height - y);
    var radius = Math.sqrt(farX * farX + farY * farY);
    // Scale factor: 12px element diameter; cover the farthest corner.
    var factor = Math.min(160, (radius * 2 + 10) / 12);
    var dot = document.createElement('span');
    dot.className = 'pro-motion-wave';
    dot.setAttribute('aria-hidden', 'true');
    dot.style.left = x + 'px';
    dot.style.top = y + 'px';
    dot.style.setProperty('--pm-wave-scale', String(factor));
    control.classList.add('pro-motion-wave-host');
    control.appendChild(dot);
    dot.addEventListener('animationend', function () { dot.remove(); }, {once:true});
  }

  document.addEventListener('pointerdown', function (event) {
    if (event.button !== 0 || event.isPrimary === false) return;
    var control = targetOf(event);
    if (!control) return;
    wave(control, event.clientX, event.clientY);
  }, {passive:true});
  document.addEventListener('keydown', function (event) {
    if (event.repeat || (event.key !== 'Enter' && event.key !== ' ')) return;
    var control = targetOf(event);
    if (!control || noMotion.matches) return;
    if (event.key === ' ' && control.tagName === 'A') return;
    control.classList.add('pro-motion-key-press');
  });
  function clearKeyboardPress(event) {
    var control = targetOf(event);
    if (control) control.classList.remove('pro-motion-key-press');
  }
  document.addEventListener('keyup', clearKeyboardPress);
  document.addEventListener('focusout', clearKeyboardPress);
  document.addEventListener('pointercancel', clearKeyboardPress);
  window.addEventListener('blur', function () {
    document.querySelectorAll('.pro-motion-key-press').forEach(function (item) {item.classList.remove('pro-motion-key-press');});
  });
})();
