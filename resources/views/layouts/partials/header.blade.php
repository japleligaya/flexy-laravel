<header class="topbar">
    <div class="with-vertical">
        <nav class="navbar navbar-expand-lg p-0" aria-label="Top navigation">
            <div class="d-flex align-items-center gap-2">
                <a class="nav-link sidebartoggler pro-top-icon" href="javascript:void(0)" id="headerCollapse" aria-label="Toggle sidebar" title="Toggle sidebar"><i class="ti ti-menu-2"></i></a>
                <div class="pro-top-divider d-none d-sm-block"></div>
                <div class="pro-top-title">
                    <span class="pro-top-overline">PAYROLL OPERATIONS</span>
                    <strong>@yield('page-title', 'Overview')</strong>
                </div>
            </div>
            <ul class="navbar-nav flex-row ms-auto align-items-center gap-2 gap-lg-3">
                <li class="nav-item d-none d-xl-block"><a href="{{ route('landing') }}" class="pro-public-link" title="View public landing page"><i class="ti ti-world"></i> Website</a></li>
                <li class="nav-item">
                    <label class="visually-hidden" for="adminAppearance">Appearance</label>
                    <select id="adminAppearance" class="pro-theme-select" data-theme-select aria-label="Appearance: system, light or dark">
                        <option value="system">System</option>
                        <option value="light">Light</option>
                        <option value="dark">Dark</option>
                    </select>
                </li>
                @can('people')<li class="nav-item d-none d-lg-block">
                    <a class="btn btn-sm pro-quick-add" href="{{ route('employees.create') }}"><i class="ti ti-plus me-1"></i> New employee</a>
                </li> @endcan
                <li class="nav-item d-none d-md-block"><span class="pro-environment"><span class="pro-env-dot"></span> Local</span></li>
                <li class="nav-item dropdown">
                    <button class="pro-account-button" type="button" id="proAccountMenu" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
                        <span class="pro-avatar">{{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                        <span class="pro-account-name d-none d-sm-inline">{{ auth()->user()->name }}</span>
                        <i class="ti ti-chevron-down d-none d-sm-inline" aria-hidden="true"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end pro-account-dropdown" aria-labelledby="proAccountMenu">
                        <div class="pro-account-dropdown-head"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->email }}</small></div>
                        <div class="dropdown-divider"></div>
                        <form method="POST" action="{{ route('logout') }}">@csrf<button class="dropdown-item d-flex align-items-center gap-2" type="submit"><i class="ti ti-logout"></i> Sign out</button></form>
                    </div>
                </li>
            </ul>
        </nav>
    </div>
</header>
