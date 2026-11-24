/* Apply saved theme before first paint to avoid flashing the wrong theme. */
(function () {
  'use strict';
  var choice = 'system';
  try {
    var stored = localStorage.getItem('payroll-go-theme');
    if (stored === 'dark' || stored === 'light' || stored === 'system') choice = stored;
  } catch (_) {}
  var dark = choice === 'dark' || (choice === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
  document.documentElement.setAttribute('data-pro-theme', choice);
  document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
})();
