/* Payroll Pro V4: lightweight public-site interactions. No external animation runtime. */
(function () {
  'use strict';
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function ready() {
    var body = document.body;
    if (!body || !body.classList.contains('pro-landing')) return;
    body.classList.add('pl-js');
    var menu = document.querySelector('[data-menu]');
    var button = document.querySelector('[data-menu-toggle]');
    function setMenu(open) {
      if (!menu || !button) return;
      menu.classList.toggle('is-open', open);
      button.setAttribute('aria-expanded', String(open));
      button.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
      var icon = button.querySelector('i');
      if (icon) icon.className = open ? 'ti ti-x' : 'ti ti-menu-2';
    }
    if (button) button.addEventListener('click', function () { setMenu(button.getAttribute('aria-expanded') !== 'true'); });
    if (menu) menu.querySelectorAll('a[href^="#"]').forEach(function (a) { a.addEventListener('click', function () { setMenu(false); }); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape') setMenu(false); });
    document.addEventListener('click', function (event) {
      if (menu && button && menu.classList.contains('is-open') && !menu.contains(event.target) && !button.contains(event.target)) setMenu(false);
    });

    // Progressive reveals: visible when JS is unavailable or reduced motion is requested.
    var revealItems = Array.from(document.querySelectorAll('[data-reveal]'));
    if (reduceMotion || !('IntersectionObserver' in window)) {
      revealItems.forEach(function (item) { item.classList.add('is-visible'); });
    } else {
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) { entry.target.classList.add('is-visible'); observer.unobserve(entry.target); }
        });
      }, { threshold: .06, rootMargin: '0px 0px -26px 0px' });
      revealItems.forEach(function (item) { observer.observe(item); });
    }

    var header = document.querySelector('[data-site-header]');
    var progress = document.querySelector('[data-reading-progress]');
    var ticking = false;
    function paintScroll() {
      ticking = false;
      if (header) header.classList.toggle('is-scrolled', window.scrollY > 8);
      if (progress) {
        var maxScroll = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
        progress.style.transform = 'scaleX(' + Math.max(0, Math.min(1, window.scrollY / maxScroll)).toFixed(4) + ')';
      }
    }
    function scrollHandler() { if (!ticking) { ticking = true; requestAnimationFrame(paintScroll); } }
    window.addEventListener('scroll', scrollHandler, { passive: true });
    window.addEventListener('resize', scrollHandler, { passive: true });
    paintScroll();

    // Mini-dashboard: real tabs, but all numbers are clearly illustrative.
    var demo = {
      overview: {overline:'OVERVIEW', heading:'Workforce overview', summary:'All the essentials, in one view.', detail:'People, attendance, and payroll—without clutter.', labels:['Employees','Departments','Present today'], values:['128','08','116']},
      employees: {overline:'TEAM DIRECTORY', heading:'Employee management', summary:'Your people, organized.', detail:'Profiles, departments, and status at a glance.', labels:['Employees','Active','Departments'],values:['128','123','08']},
      attendance: {overline:'DAILY ATTENDANCE', heading:'Attendance records', summary:'Stay on top of each day.', detail:'Simple attendance record tracking.',labels:['Present','On leave','Absent'],values:['116','09','03']},
      payroll: {overline:'PERIOD REVIEW', heading:'Payroll preparation', summary:'Prepare, check, finalize.', detail:'Manual entries with clear totals.',labels:['Entries','Drafts','Finalized'], values:['128','02','06']}
    };
    var rail = Array.from(document.querySelectorAll('[data-demo-tab]'));
    var demoRoot = document.querySelector('[data-demo-root]');
    function setText(attr, value) { var el = document.querySelector('[' + attr + ']'); if (el) el.textContent = value; }
    function selectDemo(key) {
      var item = demo[key]; if (!item) return;
      rail.forEach(function (b) { var active = b.dataset.demoTab === key; b.classList.toggle('is-active', active); b.setAttribute('aria-pressed', String(active)); });
      setText('data-demo-overline', item.overline);
      setText('data-demo-heading', item.heading);
      setText('data-demo-summary', item.summary);
      setText('data-demo-detail', item.detail);
      for (var i = 0; i < 3; i++) {
        setText('data-demo-label' + (i + 1), item.labels[i]);
        setText('data-demo-value' + (i + 1), item.values[i]);
      }
      if (demoRoot && !reduceMotion) {
        demoRoot.classList.remove('is-changing');
        void demoRoot.offsetWidth;
        demoRoot.classList.add('is-changing');
      }
    }
    rail.forEach(function (b) { b.addEventListener('click', function () { selectDemo(b.dataset.demoTab); }); });

    // Accessible product tour tabs with keyboard navigation and meaningful content.
    var showcase = {
      employees: { title:'Make every employee easy to find.', description:'Keep searchable records for names, departments, employment status and staff information.', benefits:['Searchable records','Department assignments','Clear status labels'] },
      attendance: { title:'Make each working day clearer.', description:'Review manually entered attendance statuses and filter daily records without losing your place.', benefits:['Daily attendance overview','Present, absent, and leave','Date-based review'] },
      payroll: { title:'Review every payroll period with clarity.', description:'Create a draft payroll period, enter amounts, check the totals and finalize after review.', benefits:['Manual payroll entries','Gross and net summaries','CSV report exports'] }
    };
    var tabs = Array.from(document.querySelectorAll('[data-showcase-tab]'));
    var panels = Array.from(document.querySelectorAll('[data-showcase-panel]'));
    var benefitList = document.querySelector('[data-showcase-benefits]');
    // The server already rendered the legal destination. We preserve that route rather than build URLs in JavaScript.
    function selectShowcase(key, focus) {
      var item = showcase[key]; if (!item) return;
      tabs.forEach(function (tab) {
        var active = tab.dataset.showcaseTab === key;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
        if (focus && active) tab.focus();
      });
      panels.forEach(function (panel) { panel.hidden = panel.dataset.showcasePanel !== key; });
      setText('data-showcase-title', item.title);
      setText('data-showcase-desc', item.description);
      if (benefitList) {
        benefitList.replaceChildren();
        item.benefits.forEach(function (benefit) {
          var row = document.createElement('span');
          var icon = document.createElement('i');
          icon.className = 'ti ti-circle-check';
          icon.setAttribute('aria-hidden', 'true');
          row.append(icon, document.createTextNode(' ' + benefit));
          benefitList.appendChild(row);
        });
      }
    }
    tabs.forEach(function (tab, index) {
      tab.addEventListener('click', function () { selectShowcase(tab.dataset.showcaseTab, false); });
      tab.addEventListener('keydown', function (event) {
        var next = index;
        if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
        else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = tabs.length - 1;
        else return;
        event.preventDefault(); selectShowcase(tabs[next].dataset.showcaseTab, true);
      });
    });
    document.querySelectorAll('[data-feature-tab]').forEach(function (link) {
      link.addEventListener('click', function () { selectShowcase(link.dataset.featureTab, false); });
    });

    // Bounded, desktop-only pointer tilt. Never touch scroll/touch behavior.
    var windowEl = document.querySelector('.pl-dashboard-window');
    if (windowEl && !reduceMotion && window.matchMedia('(hover:hover) and (pointer:fine)').matches) {
      var frame = 0, x = 0, y = 0;
      windowEl.addEventListener('pointermove', function (event) {
        var bounds = windowEl.getBoundingClientRect();
        x = ((event.clientX - bounds.left) / bounds.width - .5) * 3.2;
        y = -((event.clientY - bounds.top) / bounds.height - .5) * 2.4;
        if (!frame) frame = requestAnimationFrame(function () { frame = 0; windowEl.style.setProperty('--pl-rotate-y', x.toFixed(2) + 'deg'); windowEl.style.setProperty('--pl-rotate-x', y.toFixed(2) + 'deg'); });
      }, { passive: true });
      windowEl.addEventListener('pointerleave', function () { windowEl.style.setProperty('--pl-rotate-y', '0deg'); windowEl.style.setProperty('--pl-rotate-x', '0deg'); });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready, { once: true });
  else ready();
})();
