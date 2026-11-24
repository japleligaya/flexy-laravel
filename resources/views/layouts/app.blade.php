<!doctype html>
<html lang="en" dir="ltr" data-bs-theme="light" data-color-theme="Blue_Theme" data-layout="vertical">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('payroll-pro/css/payroll-experience.css') }}">
    <meta name="theme-color" content="#f7f9fc">
    <title>@yield('title', 'Payroll Pro')</title>
    <script src="{{ asset('payroll-pro/js/theme-boot.js') }}"></script>
    <link rel="icon" href="{{ asset('flexy/assets/images/logos/favicon.png') }}">
    <link rel="stylesheet" href="{{ asset('flexy/assets/css/styles.css') }}">
  <link rel="stylesheet" href="{{ asset('payroll-pro/css/brand-tokens.css') }}?v=4">
    <link rel="stylesheet" href="{{ asset('payroll-pro/css/theme.css') }}?v=5">
    <link rel="stylesheet" href="{{ asset('payroll-pro/css/admin-speed.css') }}?v=2">
    @stack('styles')
  <link rel="stylesheet" href="{{ asset('payroll-pro/css/qa-polish.css') }}?v=72">
  <link rel="stylesheet" href="{{ asset('payroll-pro/css/motion-polish.css') }}?v=75">
  <link rel="stylesheet" href="{{ asset('payroll-pro/css/mobile-motion-v8.css') }}?v=8">
</head>
<body class="payroll-app" data-pro-chart-lib="{{ asset('flexy/assets/libs/apexcharts/dist/apexcharts.min.js') }}">
    <div id="main-wrapper">
        @include('layouts.partials.sidebar')
        <div class="page-wrapper">
            @include('layouts.partials.header')
            <main class="body-wrapper" id="main-content">
                @include('layouts.partials.alerts')
                @yield('content')
                @include('layouts.partials.feature-help')
                <footer class="pro-footer" aria-label="Footer">
                    <span>Payroll Pro <span class="pro-footer-dot">·</span> Business operations</span>
                    <span><a href="{{ route('landing') }}">Public website</a> <span class="pro-footer-dot">·</span> Versioned payroll - payment recorded separately</span>
                </footer>
            </main>
        </div>
    </div>
    <div class="dark-transparent sidebartoggler"></div>
    <script src="{{ asset('flexy/assets/js/vendor.min.js') }}"></script>
    <script src="{{ asset('flexy/assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('flexy/assets/libs/simplebar/dist/simplebar.min.js') }}"></script>
    <script src="{{ asset('flexy/assets/js/theme/app.init.js') }}"></script>
    <script>if (typeof userSettings !== 'undefined') userSettings.Theme = document.documentElement.getAttribute('data-bs-theme') || 'light';</script>
    <script src="{{ asset('flexy/assets/js/theme/theme.js') }}"></script>
    <script src="{{ asset('flexy/assets/js/theme/app.min.js') }}"></script>
    <script src="{{ asset('flexy/assets/js/theme/sidebarmenu.js') }}"></script>
    <script src="{{ asset('payroll-pro/js/theme-controls.js') }}" defer></script>
    <script src="{{ asset('payroll-pro/js/dashboard.js') }}?v=2" defer></script>
    <script src="{{ asset('payroll-pro/js/payroll-experience.js') }}" defer></script>
    <script src="{{ asset('payroll-pro/js/admin-fast-nav.js') }}?v=5" defer></script>
    <script src="{{ asset('payroll-pro/js/admin-ui.js') }}?v=5" defer></script>
    @stack('scripts')
  <script src="{{ asset('payroll-pro/js/motion-polish.js') }}?v=75" defer></script>
  <script src="{{ asset('payroll-pro/js/mobile-motion-v8.js') }}?v=8" defer></script>
</body>
</html>
