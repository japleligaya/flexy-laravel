/* Shared appearance selection for public site, login and protected workspace. */
(function () {
  'use strict';
  var storageKey = 'payroll-go-theme';
  var allowed = { light: true, dark: true, system: true };
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  function getChoice() {
    try { var value = localStorage.getItem(storageKey); return allowed[value] ? value : 'system'; }
    catch (_) { return 'system'; }
  }
  function syncControls(choice) {
    document.querySelectorAll('[data-theme-select]').forEach(function (el) { el.value = choice; });
  }
  function apply(choice) {
    var dark = choice === 'dark' || (choice === 'system' && mq && mq.matches);
    var resolved = dark ? 'dark' : 'light';
    document.documentElement.setAttribute('data-bs-theme', resolved);
    document.documentElement.setAttribute('data-pro-theme', choice);
    document.documentElement.style.colorScheme = resolved;
    if (typeof userSettings !== 'undefined') userSettings.Theme = resolved;
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', dark ? '#18191a' : '#f7f9fc');
    syncControls(choice);
    document.dispatchEvent(new CustomEvent('payroll:theme-change', {detail: {choice: choice, resolved: resolved}}));
  }
  function setChoice(value) {
    if (!allowed[value]) return;
    try { localStorage.setItem(storageKey, value); } catch (_) {}
    apply(value);
  }
  function init() {
    apply(getChoice());
    document.querySelectorAll('[data-theme-select]').forEach(function (select) {
      select.addEventListener('change', function () { setChoice(select.value); });
    });
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
      button.addEventListener('click', function () {
        var input = button.parentElement.querySelector('input');
        if (!input) return;
        var visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.setAttribute('aria-pressed', visible ? 'true' : 'false');
        button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        var icon = button.querySelector('i');
        if (icon) icon.className = visible ? 'ti ti-eye-off' : 'ti ti-eye';
      });
    });
    document.querySelectorAll('[data-login-form]').forEach(function (form) {
      form.addEventListener('submit', function () {
        if (!form.checkValidity()) return;
        var btn = form.querySelector('[data-submit-button]');
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Signing in…'; }
      });
    });
  }
  if (mq) {
    var onChange = function () { if (getChoice() === 'system') apply('system'); };
    if (mq.addEventListener) mq.addEventListener('change', onChange);
    else if (mq.addListener) mq.addListener(onChange);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
