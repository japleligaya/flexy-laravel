<aside class="left-sidebar with-vertical" aria-label="Primary navigation">
    <div class="pro-sidebar-inner">
        <div class="brand-logo d-flex align-items-center justify-content-between">
            <a href="{{ route(auth()->user()->role==='employee'?'payslips.index':'dashboard') }}" class="logo-img brand-lockup d-flex align-items-center gap-2 text-decoration-none" aria-label="Payroll Pro home">
                <span class="brand-emblem" aria-hidden="true"><i class="ti ti-building-bank"></i></span>
                <span class="brand-wordmark">Payroll<span>PRO</span><small>Business Operations</small></span>
            </a>
            <a href="javascript:void(0)" class="sidebartoggler ms-auto d-block d-xl-none" aria-label="Close menu"><i class="ti ti-x"></i></a>
        </div>
        <nav class="sidebar-nav scroll-sidebar" data-simplebar aria-label="Modules">
            <ul id="sidebarnav">
                <li class="nav-small-cap"><i class="ti ti-dots nav-small-cap-icon"></i><span class="hide-menu">WORKSPACE</span></li>
@can('dashboard')                <li class="sidebar-item"><a class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page"  @endif  title="Overview"><span><i class="ti ti-layout-dashboard"></i></span><span class="hide-menu">Overview</span></a></li> @endcan
@can('people')                <li class="sidebar-item"><a class="sidebar-link {{ request()->routeIs('employees.*') ? 'active' : '' }}" href="{{ route('employees.index') }}" @if(request()->routeIs('employees.*')) aria-current="page"  @endif  title="Employees"><span><i class="ti ti-users"></i></span><span class="hide-menu">Employees</span></a></li> @endcan
@can('people')                <li class="sidebar-item"><a class="sidebar-link {{ request()->routeIs('departments.*') ? 'active' : '' }}" href="{{ route('departments.index') }}" @if(request()->routeIs('departments.*')) aria-current="page"  @endif  title="Departments"><span><i class="ti ti-sitemap"></i></span><span class="hide-menu">Departments</span></a></li> @endcan
@can('attendance_read')                <li class="sidebar-item"><a class="sidebar-link {{ request()->routeIs('attendance.*') ? 'active' : '' }}" href="{{ route('attendance.index') }}" @if(request()->routeIs('attendance.*')) aria-current="page"  @endif  title="Attendance"><span><i class="ti ti-calendar-check"></i></span><span class="hide-menu">Attendance</span></a></li> @endcan
                <li class="nav-small-cap mt-3"><i class="ti ti-dots nav-small-cap-icon"></i><span class="hide-menu">PAYROLL MANAGEMENT</span></li>
@can('read')                <li class="sidebar-item"><a class="sidebar-link {{ request()->routeIs('payroll-periods.*') || request()->routeIs('payroll-entries.*') ? 'active' : '' }}" href="{{ route('payroll-periods.index') }}" @if(request()->routeIs('payroll-periods.*') || request()->routeIs('payroll-entries.*')) aria-current="page"  @endif  title="Payroll Periods"><span><i class="ti ti-wallet"></i></span><span class="hide-menu">Payroll Periods</span></a></li> @endcan
@can('export')                <li class="sidebar-item"><a class="sidebar-link {{ request()->routeIs('reports.*') ? 'active' : '' }}" href="{{ route('reports.index') }}" @if(request()->routeIs('reports.*')) aria-current="page"  @endif  title="Reports"><span><i class="ti ti-chart-bar"></i></span><span class="hide-menu">Reports &amp; CSV</span></a></li> @endcan
                @can('settings')<li class="sidebar-item"><a class="sidebar-link" href="{{ route('payroll-settings.index') }}"><span><i class="ti ti-settings"></i></span><span class="hide-menu">Payroll settings</span></a></li> @endcan 
<li class="sidebar-item"><a class="sidebar-link" href="{{ route('payslips.index') }}"><span><i class="ti ti-file"></i></span><span class="hide-menu">My payslips</span></a></li>
<li class="nav-small-cap mt-3"><i class="ti ti-dots nav-small-cap-icon"></i><span class="hide-menu">GENERAL</span></li>
                <li class="sidebar-item"><a class="sidebar-link" href="{{ route('landing') }}"><span><i class="ti ti-world"></i></span><span class="hide-menu">Public website</span></a></li>
            </ul>
        </nav>
        <div class="pro-sidebar-status hide-menu">
            <div class="pro-sidebar-status-icon"><i class="ti ti-shield-lock"></i></div>
            <div><strong>Local workspace</strong><span>Prototype · No live payments</span></div>
        </div>
    </div>
</aside>
