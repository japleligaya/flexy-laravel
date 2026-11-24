<!doctype html>
<html lang="en" dir="ltr" data-bs-theme="light" data-color-theme="Blue_Theme">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="theme-color" content="#f7f9fc">
  <title>Sign in | Payroll Pro</title>
  <script src="{{ asset('payroll-pro/js/theme-boot.js') }}"></script>
  <link rel="stylesheet" href="{{ asset('flexy/assets/css/styles.css') }}">
  <link rel="stylesheet" href="{{ asset('payroll-pro/css/brand-tokens.css') }}?v=4">
  <link rel="stylesheet" href="{{ asset('payroll-pro/css/login.css') }}?v=4">
  <link rel="stylesheet" href="{{ asset('payroll-pro/css/qa-polish.css') }}?v=72">
  <link rel="stylesheet" href="{{ asset('payroll-pro/css/motion-polish.css') }}?v=75">
  <link rel="stylesheet" href="{{ asset('payroll-pro/css/mobile-motion-v8.css') }}?v=8">
</head>
<body class="pro-login-body">
  <a class="pro-skip" href="#login-form">Skip to sign in</a>
  <div class="pro-login-shell">
    <section class="pro-login-story" aria-label="About Payroll Pro">
      <div class="pro-login-story-top">
        <a class="pro-brand" href="{{ route('landing') }}" aria-label="Payroll Pro home"><span class="pro-brand-icon"><i class="ti ti-building-bank"></i></span><span>Payroll<span class="pro-brand-accent">PRO</span><small>Business operations</small></span></a>
        <a href="{{ route('landing') }}" class="pro-quiet-link"><i class="ti ti-arrow-left"></i> Back to website</a>
      </div>
      <div class="pro-login-story-content">
        <span class="pro-kicker"><span class="pro-kicker-dot"></span> ONE ORGANIZED WORKSPACE</span>
        <h1>Keep every payroll detail <em>in its place.</em></h1>
        <p>Bring employees, departments, attendance and payroll records together in a focused workspace built for day-to-day operations.</p>
        <div class="pro-login-highlights">
          <span><i class="ti ti-circle-check"></i> Centralized employee records</span>
          <span><i class="ti ti-circle-check"></i> Clear payroll periods</span>
          <span><i class="ti ti-circle-check"></i> Exportable reports</span>
        </div>
      </div>
      <div class="pro-login-story-bottom">Payroll Pro <span>·</span> Local development prototype</div>
    </section>
    <section class="pro-login-main" aria-labelledby="login-title">
      <div class="pro-login-toolbar"><label for="loginAppearance">Appearance</label><select id="loginAppearance" class="pro-theme-select" data-theme-select aria-label="Appearance: system, light or dark"><option value="system">System</option><option value="light">Light</option><option value="dark">Dark</option></select></div>
      <main id="login-form" class="pro-login-form-card">
        <div class="pro-login-form-icon" aria-hidden="true"><i class="ti ti-lock"></i></div>
        <p class="pro-eyebrow">WELCOME BACK</p>
        <h2 id="login-title">Sign in to Payroll Pro</h2>
        <p class="pro-login-intro">Use your administrator credentials to open the management workspace.</p>
        @if($errors->any())
          <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif
        <form method="POST" action="{{ route('login.store') }}" data-login-form>
          @csrf
          <div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control" type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="username" placeholder="you@company.com" required autofocus></div>
          <div class="mb-3"><label class="form-label" for="password">Password</label><div class="pro-password-wrap"><input class="form-control" type="password" id="password" name="password" autocomplete="current-password" placeholder="Enter your password" required><button class="pro-show-password" type="button" data-password-toggle aria-label="Show password" aria-pressed="false"><i class="ti ti-eye" aria-hidden="true"></i></button></div></div>
          <div class="pro-login-hint"><i class="ti ti-shield-lock"></i> Protected administrator workspace</div>
          <button class="pro-primary-btn pro-login-submit" type="submit" data-submit-button>Sign in <i class="ti ti-arrow-right"></i></button>
        </form>
        <div class="pro-login-footnote">No public account registration. Administrator accounts are provisioned through a local server command.</div>
      </main>
      <p class="pro-login-bottom-links"><a href="{{ route('landing') }}">Home</a> <span>·</span> Local prototype · Not for real payroll disbursement</p>
    </section>
  </div>
  <script src="{{ asset('payroll-pro/js/theme-controls.js') }}" defer></script>
  <script src="{{ asset('payroll-pro/js/motion-polish.js') }}?v=75" defer></script>
  <script src="{{ asset('payroll-pro/js/mobile-motion-v8.js') }}?v=8" defer></script>
</body>
</html>
