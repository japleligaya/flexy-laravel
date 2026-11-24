/* PayrollGo V6 — Progressive, bounded same-origin navigation.
   - Normal Laravel navigation remains the fallback.
   - Only GET links on recognized app pages are enhanced.
   - Authenticated HTML is not persistently cached; hover prefetch expires rapidly.
   - Forms, uploads, downloads, login/logout, and new-tab links stay native.
   - Diagnose response/render timing with PayrollGoPerf in the console.
*/
(function () {
  'use strict';
  var main = document.getElementById('main-content');
  if (!main || !document.body.classList.contains('payroll-app') || !window.fetch || !window.DOMParser) return;

  var SHELL = 'payroll-v6';
  var PREFETCH_MS = 1800;
  var preload = new Map();
  var maxPrefetch = 3;
  var sequence = 0;
  var activeController = null;
  var indicatorTimer = 0;
  var indicator = document.createElement('div');
  indicator.className = 'pro-nav-loading';
  indicator.setAttribute('aria-hidden', 'true');
  indicator.innerHTML = '<span></span>';
  document.body.appendChild(indicator);

  var metrics = [];
  window.PayrollGoPerf = {
    latest: function () { return metrics.length ? Object.assign({}, metrics[metrics.length - 1]) : null; },
    results: function () { return metrics.map(function (item) { return Object.assign({}, item); }); },
    initial: function () {
      var nav = performance.getEntriesByType('navigation')[0];
      return nav ? {
        serverWaitMs: Math.round(nav.responseStart - nav.requestStart),
        domReadyMs: Math.round(nav.domContentLoadedEventEnd - nav.startTime),
        fullyLoadedMs: Math.round(nav.loadEventEnd - nav.startTime)
      } : null;
    },
    show: function () { console.table(metrics); console.info('Initial page:', this.initial()); }
  };

  function allowedUrl(raw) {
    try {
      var url = new URL(raw, location.href);
      if (url.origin !== location.origin || !/^\/(?:app|employees(?:\/|$)|departments(?:\/|$)|attendance(?:\/|$)|payroll-periods(?:\/|$)|reports(?:\/|$))/.test(url.pathname)) return null;
      if (url.hash || /^\/reports\/payroll\//.test(url.pathname) || /\/(?:csv|download|export)(?:\/|$)/.test(url.pathname)) return null;
      if (url.pathname === '/app' || /^\/(?:employees|departments|attendance|payroll-periods|reports)(?:\/|$)/.test(url.pathname)) return url;
      return null;
    } catch (_) { return null; }
  }
  function eligible(anchor) {
    if (!anchor || anchor.hasAttribute('download') || anchor.hasAttribute('data-no-fast-nav') || (anchor.target && anchor.target !== '_self')) return null;
    if (!anchor.closest('#sidebarnav, #main-content, .topbar')) return null;
    return allowedUrl(anchor.href);
  }
  function urlKey(url) { return url.origin + url.pathname + url.search; }
  function showProgress() {
    clearTimeout(indicatorTimer);
    indicatorTimer = setTimeout(function () { indicator.classList.add('is-visible'); }, 135);
    main.setAttribute('aria-busy', 'true');
  }
  function hideProgress() {
    clearTimeout(indicatorTimer);
    indicator.classList.remove('is-visible');
    main.removeAttribute('aria-busy');
  }
  function fullNavigation(url, backwards) {
    if (backwards) location.reload();
    else location.assign(url.href);
  }
  function scriptsIn(doc) {
    return new Set(Array.from(doc.querySelectorAll('script[src]')).map(function (el) {
      return new URL(el.getAttribute('src'), location.href).href;
    }));
  }
  function safeDocument(doc) {
    var incoming = doc.getElementById('main-content');
    if (!incoming || !doc.querySelector('#sidebarnav') || !doc.querySelector('.topbar')) throw new Error('Unrecognized application shell');
    // A future page might bring its own inline JS or stylesheet. Do a native load
    // instead of silently dropping required initialization during a content swap.
    if (incoming.querySelector('iframe, object, embed, style, link[rel="stylesheet"]')) throw new Error('Complex content requires native navigation');
    var liveScripts = incoming.querySelectorAll('script');
    for (var i = 0; i < liveScripts.length; i++) {
      var script = liveScripts[i];
      if (script.hasAttribute('src') || !/^(application\/json|application\/ld\+json)$/i.test(script.getAttribute('type') || '')) throw new Error('Inline script requires native navigation');
    }
    var currentScripts = scriptsIn(document);
    var incomingScripts = scriptsIn(doc);
    // Only allow swaps when both pages have the same declared script assets.
    for (var src of incomingScripts) if (!currentScripts.has(src)) throw new Error('Page-specific script requires native navigation');
    var incomingStyle = new Set(Array.from(doc.querySelectorAll('link[rel="stylesheet"]')).map(function (el) { return new URL(el.getAttribute('href'), location.href).href; }));
    var currentStyle = new Set(Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(function (el) { return el.href; }));
    for (var href of incomingStyle) if (!currentStyle.has(href)) throw new Error('Page-specific stylesheet requires native navigation');
    return incoming;
  }
  async function fetchDocument(url, signal) {
    var response = await fetch(urlKey(url), {
      credentials: 'same-origin',
      redirect: 'follow',
      cache: 'no-store',
      signal: signal,
      headers: { 'Accept': 'text/html', 'X-Requested-With': 'PayrollGoNavigation' }
    });
    if (!response.ok || !(response.headers.get('content-type') || '').toLowerCase().includes('text/html')) throw new Error('Unexpected response: ' + response.status);
    var returned = allowedUrl(response.url);
    if (!returned || urlKey(returned) !== urlKey(url)) throw new Error('Redirected; native navigation required');
    var html = await response.text();
    var doc = new DOMParser().parseFromString(html, 'text/html');
    safeDocument(doc);
    return doc;
  }
  function fetchForClick(url, signal) {
    var key = urlKey(url);
    var item = preload.get(key);
    if (item && Date.now() - item.created <= PREFETCH_MS) {
      preload.delete(key);
      return item.promise.then(function (doc) { return { doc: doc, prefetched: true }; });
    }
    return fetchDocument(url, signal).then(function (doc) { return { doc: doc, prefetched: false }; });
  }
  function syncShell(doc) {
    var selected = new Map();
    doc.querySelectorAll('#sidebarnav a.sidebar-link[href]').forEach(function (anchor) {
      selected.set(anchor.href, anchor.classList.contains('active'));
    });
    document.querySelectorAll('#sidebarnav a.sidebar-link[href]').forEach(function (anchor) {
      var isActive = selected.get(anchor.href) === true;
      anchor.classList.toggle('active', isActive);
      if (isActive) anchor.setAttribute('aria-current', 'page');
      else anchor.removeAttribute('aria-current');
    });
    var actualTitle = document.querySelector('.pro-top-title strong');
    var nextTitle = doc.querySelector('.pro-top-title strong');
    if (actualTitle && nextTitle) actualTitle.textContent = nextTitle.textContent;
    var actualCsrf = document.querySelector('meta[name="csrf-token"]');
    var nextCsrf = doc.querySelector('meta[name="csrf-token"]');
    if (actualCsrf && nextCsrf) actualCsrf.content = nextCsrf.content;
    document.title = doc.title;
  }
  function closeMobileSidebar() {
    if (window.innerWidth >= 1200) return;
    var overlay = document.querySelector('.dark-transparent.show');
    if (overlay) overlay.click();
  }
  function render(doc, url, backwards, targetY) {
    var next = safeDocument(doc);
    if (window.PayrollGoCharts && typeof window.PayrollGoCharts.destroy === 'function') window.PayrollGoCharts.destroy();
    // Static Blade pages from this app only. Executable inline scripts are excluded.
    main.innerHTML = next.innerHTML;
    syncShell(doc);
    closeMobileSidebar();
    if (!backwards) history.pushState({ payrollFast: true, proScrollY: 0 }, '', url.href);
    main.classList.remove('pro-page-in');
    if (!matchMedia('(prefers-reduced-motion: reduce)').matches && !document.hidden) {
      void main.offsetWidth;
      main.classList.add('pro-page-in');
    }
    scrollTo({ top: backwards ? targetY : 0, behavior: 'instant' });
    main.setAttribute('tabindex', '-1');
    main.focus({ preventScroll: true });
    document.dispatchEvent(new CustomEvent('payroll:navigated', { detail: { url: url.href } }));
  }
  function captureScrollPosition() {
    var state = Object.assign({}, history.state || {});
    state.proScrollY = Math.round(window.scrollY);
    history.replaceState(state, '', location.href);
  }
  async function navigate(url, backwards, targetY) {
    sequence++;
    var callId = sequence;
    if (activeController) activeController.abort();
    activeController = new AbortController();
    var started = performance.now();
    showProgress();
    var fetchedAt = 0;
    try {
      var result = await fetchForClick(url, activeController.signal);
      fetchedAt = performance.now();
      if (callId !== sequence) return;
      render(result.doc, url, backwards, targetY || 0);
      var finished = performance.now();
      metrics.push({
        route: url.pathname,
        requestMs: Math.round(fetchedAt - started),
        renderMs: Math.round(finished - fetchedAt),
        totalMs: Math.round(finished - started),
        prefetched: result.prefetched
      });
      if (metrics.length > 30) metrics.shift();
    } catch (error) {
      if (callId !== sequence) return;
      fullNavigation(url, backwards);
    } finally {
      if (callId === sequence) { hideProgress(); activeController = null; }
    }
  }
  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.shiftKey || event.altKey || event.metaKey) return;
    var anchor = event.target.closest && event.target.closest('a[href]');
    var url = eligible(anchor);
    if (!url || urlKey(url) === urlKey(new URL(location.href))) return;
    // Do not swap pages over an open Bootstrap modal.
    if (document.querySelector('.modal.show')) return;
    event.preventDefault();
    captureScrollPosition();
    navigate(url, false, 0);
  });
  var prefetchTimer = 0;
  function prefetchFrom(event) {
    var anchor = event.target.closest && event.target.closest('#sidebarnav a[href], .topbar a[href]');
    var url = eligible(anchor);
    if (!url || urlKey(url) === urlKey(new URL(location.href))) return;
    var connection = navigator.connection;
    if (connection && (connection.saveData || /(?:slow-2g|2g)/.test(connection.effectiveType || ''))) return;
    clearTimeout(prefetchTimer);
    prefetchTimer = setTimeout(function () {
      var key = urlKey(url);
      var prev = preload.get(key);
      if (prev && Date.now() - prev.created < PREFETCH_MS) return;
      var promise = fetchDocument(url).catch(function (err) { preload.delete(key); throw err; });
      // Avoid an unhandled rejection when the mouse leaves without a click.
      promise.catch(function () {});
      preload.set(key, { created: Date.now(), promise: promise });
      if (preload.size > maxPrefetch) preload.delete(preload.keys().next().value);
    }, 145);
  }
  document.addEventListener('pointerover', prefetchFrom, { passive: true });
  document.addEventListener('focusin', prefetchFrom);
  document.addEventListener('pointerout', function (event) {
    if (event.target.closest && event.target.closest('#sidebarnav a[href], .topbar a[href]')) clearTimeout(prefetchTimer);
  }, { passive: true });
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
  addEventListener('popstate', function (event) {
    var url = allowedUrl(location.href);
    if (!url) { location.reload(); return; }
    navigate(url, true, event.state && Number(event.state.proScrollY) || 0);
  });
})();
