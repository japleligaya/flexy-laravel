/* Payroll Pro V8 — mobile tap + viewport motion. No navigation interception.
   Delegated pointer handlers survive fetch-navigation and do not delay clicks. */
(function () {
  'use strict';
  if (window.__payrollMobileMotionV8) return;
  window.__payrollMobileMotionV8 = true;
  var mobile = window.matchMedia('(max-width: 991.98px)');
  var coarse = window.matchMedia('(hover: none), (pointer: coarse)');
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  var selector = 'button, .btn, .pl-button, .pl-rail-item, .pro-primary-btn, .sidebar-link, .page-link';
  var revealSelector = '.pro-landing .pl-feature, .pro-landing .pl-workflow-step, .pro-landing .pl-final-card, .payroll-app .pro-admin-v5 .pro-module-card, .payroll-app .pro-admin-v5 .pro-pay-kpi, .payroll-app .pro-admin-v5 .pro-module-intro';
  var observer = null;
  var touchTimer = new WeakMap();
  function scopeOK(node) { return !!node.closest('body.pro-landing,body.pro-login-body,body.payroll-app'); }
  function buttonOf(e) {
    var n = e.target;
    if (!n || !n.closest) return null;
    var btn = n.closest(selector);
    return btn && scopeOK(btn) && !btn.matches(':disabled,[disabled],[aria-disabled="true"],.disabled') ? btn : null;
  }
  function release(node, immediate) {
    if (!node) return;
    var old = touchTimer.get(node);
    if (old) clearTimeout(old);
    if (immediate) {node.classList.remove('pm-touch-active'); return;}
    touchTimer.set(node, setTimeout(function () { node.classList.remove('pm-touch-active'); }, 100));
  }
  document.addEventListener('pointerdown', function (e) {
    if (!coarse.matches || reduced.matches || (e.pointerType === 'mouse') || e.isPrimary === false) return;
    var node = buttonOf(e);
    if (!node) return;
    release(node, true);
    node.classList.add('pm-touch-active');
  }, {passive:true});
  ['pointerup','pointercancel'].forEach(function (type) {
    document.addEventListener(type, function (e) {
      var node = buttonOf(e);
      if (node) release(node, type === 'pointercancel');
    }, {passive:true});
  });
  document.addEventListener('click', function (e) {
    // Restore buttons tapped via synthesized click or keyboard on touchscreen.
    if (!coarse.matches || reduced.matches) return;
    var node = buttonOf(e);
    if (node && node.classList.contains('pm-touch-active')) release(node, false);
  }, {passive:true});
  function cleanup() {
    document.querySelectorAll('.pm-touch-active').forEach(function (el) {release(el,true);});
  }
  window.addEventListener('pagehide',cleanup);
  function initializeReveals() {
    if (observer) {observer.disconnect(); observer=null;}
    document.querySelectorAll('.pm-mobile-reveal').forEach(function (el) {
      el.classList.remove('pm-mobile-reveal'); el.classList.remove('pm-in-view');
    });
    if (!mobile.matches || reduced.matches || !('IntersectionObserver' in window)) return;
    observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('pm-in-view');
        observer.unobserve(entry.target);
      });
    }, {threshold:0.08, rootMargin:'0px 0px 20px 0px'});
    document.querySelectorAll(revealSelector).forEach(function (node) {
      // Skip elements that were already animated by the V4 landing script.
      if (node.hasAttribute('data-reveal')) return;
      var rect=node.getBoundingClientRect();
      if (rect.height <= 0) return;
      node.classList.add('pm-mobile-reveal');
      // Force layout before observing so the fade can be seen on first load.
      void node.offsetHeight;
      observer.observe(node);
    });
  }
  var reinitPending=false;
  function scheduleInit() {
    if (reinitPending) return;
    reinitPending=true;
    requestAnimationFrame(function () {reinitPending=false;initializeReveals();});
  }
  document.addEventListener('payroll:navigated', scheduleInit);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scheduleInit);
  else scheduleInit();
  if (mobile.addEventListener) mobile.addEventListener('change', scheduleInit);
  if (reduced.addEventListener) reduced.addEventListener('change', scheduleInit);
})();
