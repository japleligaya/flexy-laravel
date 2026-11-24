<!doctype html>
<html lang="en" dir="ltr" data-bs-theme="light" data-color-theme="Blue_Theme">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#f7f9fc">
  <meta name="description" content="Payroll Pro: a focused local workspace for employee records, attendance, payroll periods and CSV reports.">
  <title>Payroll Pro — Payroll, with a clearer view.</title>
  <script src="{{ asset('payroll-pro/js/theme-boot.js') }}"></script>
  <link rel="icon" href="{{ asset('flexy/assets/images/logos/favicon.png') }}">
  <link rel="stylesheet" href="{{ asset('flexy/assets/css/styles.css') }}">
  <link rel="stylesheet" href="{{ asset('payroll-pro/css/brand-tokens.css') }}?v=4">
  <link rel="stylesheet" href="{{ asset('payroll-pro/css/landing.css') }}?v=4">
  <link rel="stylesheet" href="{{ asset('payroll-pro/css/qa-polish.css') }}?v=72">
  <link rel="stylesheet" href="{{ asset('payroll-pro/css/motion-polish.css') }}?v=75">
  <link rel="stylesheet" href="{{ asset('payroll-pro/css/mobile-motion-v8.css') }}?v=8">
</head>
<body class="pro-landing">
  <a class="pl-skip" href="#main">Skip to content</a>
  <div class="pl-progress" aria-hidden="true"><span data-reading-progress></span></div>

  <header class="pl-header" data-site-header>
    <nav class="pl-container pl-nav" aria-label="Main menu">
      <a href="{{ route('landing') }}" class="pl-brand" aria-label="Payroll Pro home">
        <span class="pl-brand-mark"><i class="ti ti-wallet" aria-hidden="true"></i></span>
        <span class="pl-brand-words">Payroll<span>PRO</span><small>Business operations</small></span>
      </a>
      <button class="pl-menu-button" type="button" data-menu-toggle aria-controls="plMenu" aria-expanded="false" aria-label="Open navigation"><i class="ti ti-menu-2"></i></button>
      <div class="pl-nav-links" id="plMenu" data-menu>
        <a href="#features" data-nav-link>Features</a>
        <a href="#showcase" data-nav-link>Live preview</a>
        <a href="#workflow" data-nav-link>How it works</a>
        <a href="#faq" data-nav-link>FAQ</a>
        <div class="pl-nav-actions">
          <label class="pl-sr-only" for="plAppearance">Appearance</label>
          <select class="pl-theme-select" id="plAppearance" data-theme-select aria-label="Appearance"><option value="system">System</option><option value="light">Light</option><option value="dark">Dark</option></select>
          <a class="pl-button pl-button-primary pl-nav-login" href="{{ auth()->check() ? route('dashboard') : route('login') }}">{{ auth()->check() ? 'Dashboard' : 'Sign in' }} <i class="ti ti-arrow-up-right" aria-hidden="true"></i></a>
        </div>
      </div>
    </nav>
  </header>

  <main id="main">
    <section class="pl-hero" aria-labelledby="plHeroTitle">
      <div class="pl-container pl-hero-grid">
        <div class="pl-hero-copy" data-reveal>
          <span class="pl-eyebrow"><span class="pl-status-dot"></span> A MORE ORGANIZED PAYROLL WORKSPACE</span>
          <h1 id="plHeroTitle">Less paperwork.<br><span>More clarity.</span></h1>
          <p>Manage employee profiles, track attendance, and prepare payroll records in one carefully designed workspace. Built on Laravel, styled for the way real teams work.</p>
          <div class="pl-hero-actions">
            <a href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="pl-button pl-button-primary pl-button-large">{{ auth()->check() ? 'Open your workspace' : 'Explore the workspace' }} <i class="ti ti-arrow-right" aria-hidden="true"></i></a>
            <a class="pl-button pl-button-quiet pl-button-large" href="#showcase">See how it looks <i class="ti ti-player-play" aria-hidden="true"></i></a>
          </div>
          <div class="pl-hero-trust"><span><i class="ti ti-circle-check" aria-hidden="true"></i> Employee management</span><span><i class="ti ti-circle-check" aria-hidden="true"></i> Payroll reviews</span><span><i class="ti ti-circle-check" aria-hidden="true"></i> CSV exports</span></div>
          <div class="pl-prototype-note"><i class="ti ti-info-circle" aria-hidden="true"></i> Local development prototype — not for live salary disbursements.</div>
        </div>

        <div class="pl-hero-visual" data-reveal data-reveal-delay="1" aria-label="Interactive illustrated preview of Payroll Pro">
          <div class="pl-visual-orbit" aria-hidden="true"></div>
          <div class="pl-dashboard-window" data-demo-root>
            <div class="pl-window-top"><div class="pl-window-dots" aria-hidden="true"><i></i><i></i><i></i></div><span><i class="ti ti-lock" aria-hidden="true"></i> Payroll Pro · preview</span><span class="pl-window-mini">DEMO</span></div>
            <div class="pl-window-body">
              <aside class="pl-demo-rail" aria-label="Preview modules"><div class="pl-rail-logo"><i class="ti ti-wallet"></i></div><button class="pl-rail-item is-active" type="button" aria-pressed="true" data-demo-tab="overview" aria-label="Preview overview" title="Overview"><i class="ti ti-layout-dashboard"></i></button><button class="pl-rail-item" type="button" aria-pressed="false" data-demo-tab="employees" aria-label="Preview employees" title="Employees"><i class="ti ti-users"></i></button><button class="pl-rail-item" type="button" aria-pressed="false" data-demo-tab="attendance" aria-label="Preview attendance" title="Attendance"><i class="ti ti-calendar-check"></i></button><button class="pl-rail-item" type="button" aria-pressed="false" data-demo-tab="payroll" aria-label="Preview payroll" title="Payroll"><i class="ti ti-wallet"></i></button></aside>
              <div class="pl-demo-main">
                <div class="pl-demo-heading"><div><small data-demo-overline>OVERVIEW</small><h2 data-demo-heading>Workforce overview</h2></div><span class="pl-demo-avatar">PP</span></div>
                <div class="pl-demo-summary"><div><small>YOUR WORKSPACE</small><h3 data-demo-summary>All the essentials, in one view.</h3><p data-demo-detail>People, attendance, and payroll—without clutter.</p></div><div class="pl-summary-art" aria-hidden="true"><i class="ti ti-chart-dots-3"></i></div></div>
                <div class="pl-demo-kpis" aria-live="polite"><div><span data-demo-label1>Employees</span><strong data-demo-value1>128</strong><small>Illustrative</small></div><div><span data-demo-label2>Departments</span><strong data-demo-value2>08</strong><small>Illustrative</small></div><div><span data-demo-label3>Present today</span><strong data-demo-value3>116</strong><small>Illustrative</small></div></div>
                <div class="pl-demo-bottom"><div class="pl-demo-chart"><div class="pl-demo-chart-heading"><b>Payroll activity</b><span>Sample trend</span></div><svg viewBox="0 0 340 116" preserveAspectRatio="none" role="img" aria-label="Illustrative rising activity line"><path class="pl-gridline" d="M0 25H340M0 58H340M0 91H340"/><path class="pl-area" d="M0 96 C38 91 47 63 78 76 S125 101 159 51 S212 80 253 36 S310 46 340 11 V116 H0Z"/><path class="pl-line" d="M0 96 C38 91 47 63 78 76 S125 101 159 51 S212 80 253 36 S310 46 340 11"/></svg></div><div class="pl-demo-activity"><div class="pl-demo-chart-heading"><b>Status</b><span>Sample</span></div><div class="pl-ring-chart"><span>72%</span></div><small>Drafts reviewed</small></div></div>
              </div>
            </div>
          </div>
          <div class="pl-visual-float" aria-hidden="true"><span><i class="ti ti-circle-check"></i></span><div><b>All records in one place</b><small>Focused work. Fewer tabs.</small></div></div>
          <p class="pl-demo-disclaimer">Interactive design preview · all figures are illustrative</p>
        </div>
      </div>
    </section>

    <section class="pl-trustbar" aria-label="Core tools"><div class="pl-container pl-trustbar-inner"><span>ONE WORKSPACE, CORE OPERATIONS</span><div><i class="ti ti-users"></i> Employees</div><div><i class="ti ti-calendar-check"></i> Attendance</div><div><i class="ti ti-wallet"></i> Payroll</div><div><i class="ti ti-file-analytics"></i> Reports</div></div></section>

    <section class="pl-section pl-features" id="features"><div class="pl-container">
      <div class="pl-section-top" data-reveal><div><span class="pl-kicker">BUILT FOR FOCUS</span><h2>Everything important.<br><em>Nothing in the way.</em></h2></div><p>Clear screens and consistent actions for day-to-day operations—from onboarding your team to preparing a payroll period.</p></div>
      <div class="pl-bento">
        <article class="pl-feature pl-feature-wide" data-reveal><span class="pl-feature-icon pl-blue"><i class="ti ti-users-group"></i></span><div><span class="pl-feature-eyebrow">EMPLOYEE MANAGEMENT</span><h3>All your people, organized.</h3><p>Searchable employee records, department assignments, and active or inactive statuses.</p><a href="#showcase" data-feature-tab="employees">Preview employee management <i class="ti ti-arrow-up-right"></i></a></div><div class="pl-people-visual" aria-hidden="true"><div><span class="pl-person-avi">JS</span><span><b>Jamie S.</b><small>Operations</small></span><i class="ti ti-circle-check"></i></div><div><span class="pl-person-avi pl-avi-peach">AL</span><span><b>Alex L.</b><small>Finance</small></span><i class="ti ti-circle-check"></i></div><div><span class="pl-person-avi pl-avi-green">RM</span><span><b>Robin M.</b><small>Human Resources</small></span><i class="ti ti-circle-check"></i></div></div></article>
        <article class="pl-feature" data-reveal><span class="pl-feature-icon pl-peach"><i class="ti ti-calendar-stats"></i></span><span class="pl-feature-eyebrow">ATTENDANCE</span><h3>Know who's accounted for.</h3><p>Record present, absent, and leave statuses by date with clear review screens.</p><div class="pl-feature-pills"><span>Present</span><span>On leave</span><span>Absent</span></div></article>
        <article class="pl-feature" data-reveal><span class="pl-feature-icon pl-green"><i class="ti ti-file-invoice"></i></span><span class="pl-feature-eyebrow">PAYROLL PERIODS</span><h3>Prepare with confidence.</h3><p>Enter earnings and deductions, review totals, and finalize payroll entries.</p><div class="pl-progress-illustration"><span>Draft period</span><div><i></i><i></i><i></i></div><small>Review before finalizing</small></div></article>
        <article class="pl-feature pl-feature-band" data-reveal><div><span class="pl-feature-eyebrow">REPORTS & EXPORT</span><h3>Useful records, ready to export.</h3><p>Download CSV payroll summaries when you need a portable record.</p></div><span class="pl-feature-band-icon"><i class="ti ti-file-download"></i></span><a href="#workflow">See the workflow <i class="ti ti-arrow-right"></i></a></article>
      </div>
    </div></section>

    <section class="pl-section pl-showcase-section" id="showcase"><div class="pl-container">
      <div class="pl-section-top" data-reveal><div><span class="pl-kicker">SEE IT IN ACTION</span><h2>Get to know your<br><em>workspace.</em></h2></div><p>Explore the screens below. This interactive demonstration uses sample data and does not modify your records.</p></div>
      <div class="pl-showcase" data-reveal><div class="pl-showcase-toolbar"><div class="pl-tabs" role="tablist" aria-label="Workspace preview"><button type="button" class="is-active" role="tab" aria-selected="true" aria-controls="plPanelEmployees" tabindex="0" id="plTabEmployees" data-showcase-tab="employees"><i class="ti ti-users"></i> Employees</button><button type="button" role="tab" aria-selected="false" aria-controls="plPanelAttendance" tabindex="-1" id="plTabAttendance" data-showcase-tab="attendance"><i class="ti ti-calendar-check"></i> Attendance</button><button type="button" role="tab" aria-selected="false" aria-controls="plPanelPayroll" tabindex="-1" id="plTabPayroll" data-showcase-tab="payroll"><i class="ti ti-wallet"></i> Payroll</button></div><span class="pl-showcase-label"><span></span> INTERACTIVE PREVIEW</span></div>
      <div class="pl-showcase-grid"><div class="pl-showcase-description"><span class="pl-kicker">WORKSPACE DETAIL</span><h3 data-showcase-title>Make every employee easy to find.</h3><p data-showcase-desc>Keep searchable records for names, departments, employment status and staff information.</p><div class="pl-bullet-list" data-showcase-benefits><span><i class="ti ti-circle-check"></i> Searchable records</span><span><i class="ti ti-circle-check"></i> Department assignments</span><span><i class="ti ti-circle-check"></i> Clear status labels</span></div><a class="pl-text-link" href="{{ auth()->check() ? route('employees.index') : route('login') }}" data-showcase-destination>{{ auth()->check() ? 'Open Employees' : 'Sign in to explore' }} <i class="ti ti-arrow-up-right"></i></a></div>
      <div class="pl-showcase-screen"><div class="pl-screen-top"><span><i class="ti ti-lock"></i> Sample workspace</span><span>UI PREVIEW</span></div>
        <div role="tabpanel" id="plPanelEmployees" aria-labelledby="plTabEmployees" data-showcase-panel="employees"><div class="pl-screen-heading"><div><small>PEOPLE / DIRECTORY</small><h4>Employee management</h4></div><span class="pl-pill pl-pill-blue">03 sample profiles</span></div><div class="pl-mock-search"><i class="ti ti-search"></i> Search employees... <kbd>Ctrl K</kbd></div><div class="pl-mock-table"><div class="pl-table-row pl-table-head"><span>EMPLOYEE</span><span>DEPARTMENT</span><span>STATUS</span></div><div class="pl-table-row"><span><b class="pl-initial">JS</b><span>Jamie S.<small>EMP-001</small></span></span><span>Operations</span><span><em class="pl-state">Active</em></span></div><div class="pl-table-row"><span><b class="pl-initial pl-avi-peach">AL</b><span>Alex L.<small>EMP-002</small></span></span><span>Finance</span><span><em class="pl-state">Active</em></span></div><div class="pl-table-row"><span><b class="pl-initial pl-avi-green">RM</b><span>Robin M.<small>EMP-003</small></span></span><span>HR</span><span><em class="pl-state pl-inactive">Inactive</em></span></div></div></div>
        <div role="tabpanel" id="plPanelAttendance" aria-labelledby="plTabAttendance" data-showcase-panel="attendance" hidden><div class="pl-screen-heading"><div><small>TEAM / ATTENDANCE</small><h4>Daily attendance</h4></div><span class="pl-pill">Sample day</span></div><div class="pl-attendance-pills"><div><i class="ti ti-circle-check"></i><strong>24</strong><span>Present</span></div><div><i class="ti ti-calendar-minus"></i><strong>02</strong><span>Leave</span></div><div><i class="ti ti-circle-x"></i><strong>01</strong><span>Absent</span></div></div><div class="pl-bar-label">Illustrative weekly attendance trend</div><div class="pl-mock-bars"><div style="--bar-height:76%"><span>M</span></div><div style="--bar-height:98%"><span>T</span></div><div style="--bar-height:84%"><span>W</span></div><div style="--bar-height:93%"><span>T</span></div><div style="--bar-height:87%"><span>F</span></div><div style="--bar-height:45%"><span>S</span></div><div style="--bar-height:30%"><span>S</span></div></div></div>
        <div role="tabpanel" id="plPanelPayroll" aria-labelledby="plTabPayroll" data-showcase-panel="payroll" hidden><div class="pl-screen-heading"><div><small>PAYROLL / DRAFT</small><h4>Payroll period</h4></div><span class="pl-pill">Example draft</span></div><div class="pl-net-banner"><span>Sample net pay</span><strong>₱148,250.00</strong><small>Illustrative only · example figures</small></div><div class="pl-net-row"><span>Base pay</span><b>₱135,000.00</b></div><div class="pl-net-row"><span>Allowances and overtime</span><b>₱20,000.00</b></div><div class="pl-net-row"><span>Entered deductions</span><b>−₱6,750.00</b></div><p class="pl-net-warning"><i class="ti ti-info-circle"></i> Statutory deductions are not automated yet.</p></div>
      </div></div></div>
    </div></section>

    <section class="pl-section pl-workflow-section" id="workflow"><div class="pl-container"><div class="pl-section-top" data-reveal><div><span class="pl-kicker">HOW IT WORKS</span><h2>A clear path from<br><em>team to payroll.</em></h2></div><p>Four straightforward actions connect the main tools you already have. Attendance-based daily drafts are available for configured daily rates; all payroll requires review.</p></div><div class="pl-workflow-grid"><div class="pl-workflow-step" data-reveal><span>01 <i class="ti ti-users"></i></span><h3>Build your team</h3><p>Create departments and register employee profiles.</p></div><div class="pl-workflow-step" data-reveal><span>02 <i class="ti ti-calendar-check"></i></span><h3>Track attendance</h3><p>Record daily statuses and review them by date.</p></div><div class="pl-workflow-step" data-reveal><span>03 <i class="ti ti-wallet"></i></span><h3>Prepare payroll</h3><p>Review daily-rate drafts or manually enter earnings and deductions; finalize after review.</p></div><div class="pl-workflow-step" data-reveal><span>04 <i class="ti ti-file-export"></i></span><h3>Export records</h3><p>Download CSV summaries for your records.</p></div></div></div></section>

    <section class="pl-section pl-faq-section" id="faq"><div class="pl-container pl-faq-grid"><div data-reveal><span class="pl-kicker">QUESTIONS & ANSWERS</span><h2>Good to<br><em>know.</em></h2><p>Payroll Pro is being built step by step. Here's what the current prototype supports.</p></div><div class="pl-faq-list" data-reveal><details><summary>Is this ready for real payroll payments?<i class="ti ti-plus"></i></summary><p>No. This is a local prototype. Payment disbursement and production controls have not been implemented.</p></details><details><summary>Does it calculate SSS, PhilHealth, Pag-IBIG and tax?<i class="ti ti-plus"></i></summary><p>Not yet. The current payroll entries use manually entered earnings and deductions. Statutory rules require separate implementation and verification.</p></details><details><summary>Can I use light, dark and system mode?<i class="ti ti-plus"></i></summary><p>Yes. Choose an appearance from the navigation; your preference is saved in this browser. Dark uses neutral charcoal instead of navy.</p></details><details><summary>Can anyone register as an employee?<i class="ti ti-plus"></i></summary><p>No public self-registration exists yet. Administrator accounts are created through a local server command.</p></details></div></div></section>

    <section class="pl-final-section"><div class="pl-container"><div class="pl-final-card" data-reveal><div><span class="pl-kicker">READY TO EXPLORE?</span><h2>Make work feel<br><em>less complicated.</em></h2><p>Open the workspace and explore the modules available in the local development build.</p></div><a class="pl-button pl-button-primary pl-button-large" href="{{ auth()->check() ? route('dashboard') : route('login') }}">{{ auth()->check() ? 'Open dashboard' : 'Sign in to Payroll Pro' }} <i class="ti ti-arrow-right"></i></a></div></div></section>
  </main>
  <footer class="pl-footer"><div class="pl-container"><div class="pl-footer-main"><a href="{{ route('landing') }}" class="pl-brand"><span class="pl-brand-mark"><i class="ti ti-wallet"></i></span><span class="pl-brand-words">Payroll<span>PRO</span><small>Business operations</small></span></a><div><a href="#features">Features</a><a href="#showcase">Preview</a><a href="#workflow">Workflow</a><a href="{{ auth()->check() ? route('dashboard') : route('login') }}">{{ auth()->check() ? 'Dashboard' : 'Sign in' }}</a></div></div><div class="pl-footer-bottom"><span>Payroll Pro · Laravel + Flexy UI</span><span>Prototype only · No live payroll processing</span></div></div></footer>
  <script src="{{ asset('payroll-pro/js/theme-controls.js') }}" defer></script>
  <script src="{{ asset('payroll-pro/js/landing.js') }}?v=4" defer></script>
  <script src="{{ asset('payroll-pro/js/motion-polish.js') }}?v=75" defer></script>
  <script src="{{ asset('payroll-pro/js/mobile-motion-v8.js') }}?v=8" defer></script>
</body>
</html>
