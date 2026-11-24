@extends('layouts.app')
@section('title', 'Overview | Payroll Go')
@section('page-title', 'Overview')
@section('content')
<div class="container-fluid pro-page">
    <div class="pro-page-heading">
        <div>
            <p class="eyebrow">WORKSPACE / DASHBOARD</p>
            <h1>Good to see you, {{ auth()->user()->name }}.</h1>
            <p>Your workforce and payroll, all in one clear view.</p>
        </div>
        <div class="pro-heading-actions">
            <a href="{{ route('reports.index') }}" class="btn btn-outline-primary"><i class="ti ti-file-export me-1"></i> Reports</a>
            <a href="{{ route('employees.create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i> Add employee</a>
        </div>
    </div>

    <section class="pro-welcome-card" aria-label="Payroll overview intro">
        <div class="pro-welcome-copy">
            <span class="pro-welcome-kicker"><span class="pro-live-dot"></span> Payroll Go WORKSPACE</span>
            <h2>Simple. Fast. <span>Reliable.</span></h2>
            <p>Track people, manage attendance, and review manual payroll entries with confidence.</p>
            <a href="{{ route('payroll-periods.index') }}" class="pro-welcome-link">Manage payroll periods <i class="ti ti-arrow-up-right"></i></a>
        </div>
        <div class="pro-welcome-art" aria-hidden="true">
            <span class="pro-art-ring ring-one"></span><span class="pro-art-ring ring-two"></span>
            <div class="pro-art-main"><i class="ti ti-chart-arcs"></i></div>
            <span class="pro-art-accent"><i class="ti ti-currency-peso"></i></span>
        </div>
    </section>

    <section class="row g-3 g-xl-4 pro-metrics" aria-label="Key metrics">
        <div class="col-sm-6 col-xl-3">
            <a href="{{ route('employees.index') }}" class="card pro-stat-card h-100">
                <div class="card-body"><div class="pro-stat-top"><span class="pro-stat-icon blue"><i class="ti ti-users"></i></span><i class="ti ti-arrow-up-right pro-stat-arrow"></i></div>
                <span class="pro-stat-label">Active employees</span><strong class="pro-stat-number">{{ number_format($activeEmployeeCount) }}</strong><span class="pro-stat-foot">Manage workforce <i class="ti ti-arrow-right"></i></span></div>
            </a>
        </div>
        <div class="col-sm-6 col-xl-3">
            <a href="{{ route('departments.index') }}" class="card pro-stat-card h-100">
                <div class="card-body"><div class="pro-stat-top"><span class="pro-stat-icon orange"><i class="ti ti-sitemap"></i></span><i class="ti ti-arrow-up-right pro-stat-arrow"></i></div>
                <span class="pro-stat-label">Departments</span><strong class="pro-stat-number">{{ number_format($departmentCount) }}</strong><span class="pro-stat-foot">View teams <i class="ti ti-arrow-right"></i></span></div>
            </a>
        </div>
        <div class="col-sm-6 col-xl-3">
            <a href="{{ route('attendance.index') }}" class="card pro-stat-card h-100">
                <div class="card-body"><div class="pro-stat-top"><span class="pro-stat-icon green"><i class="ti ti-calendar-check"></i></span><i class="ti ti-arrow-up-right pro-stat-arrow"></i></div>
                <span class="pro-stat-label">Present today</span><strong class="pro-stat-number">{{ number_format($attendanceCount) }}</strong><span class="pro-stat-foot">Attendance records <i class="ti ti-arrow-right"></i></span></div>
            </a>
        </div>
        <div class="col-sm-6 col-xl-3">
            <a href="{{ route('payroll-periods.index') }}" class="card pro-stat-card h-100">
                <div class="card-body"><div class="pro-stat-top"><span class="pro-stat-icon purple"><i class="ti ti-file-invoice"></i></span><i class="ti ti-arrow-up-right pro-stat-arrow"></i></div>
                <span class="pro-stat-label">Draft payroll periods</span><strong class="pro-stat-number">{{ number_format($draftPeriodCount) }}</strong><span class="pro-stat-foot">Review drafts <i class="ti ti-arrow-right"></i></span></div>
            </a>
        </div>
    </section>

    <section class="row g-3 g-xl-4 align-items-stretch" aria-label="Payroll details">
        <div class="col-xxl-8 col-xl-7">
            <div class="card pro-surface-card h-100">
                <div class="card-body">
                    <div class="pro-card-heading"><div><span class="pro-overline">PAYROLL ANALYTICS</span><h3>Latest net pay breakdown</h3><p>Up to five recent entries from the latest payroll period</p></div><span class="pro-card-heading-icon"><i class="ti ti-chart-bar"></i></span></div>
                    @if($latestEntries->isNotEmpty())
                        @php
                            $payrollChartData = $latestEntries->map(fn ($entry) => [
                                'name' => $entry->employee->full_name,
                                'net' => $entry->net_pay_cents / 100,
                            ])->values()->all();
                        @endphp
                        <div id="pro-payroll-chart" role="img" aria-label="Chart of net pay for the five most recent employee payroll entries" style="min-height:280px"></div>
                        <script id="pro-payroll-chart-data" type="application/json">@json($payrollChartData)</script>
                    @else
                        <div class="pro-empty-chart"><div class="pro-empty-graphic"><i class="ti ti-chart-bar"></i><span></span></div><h4>Insights will appear here</h4><p>After you create a payroll period and add entries, this chart will show real employee net-pay values.</p><a class="btn btn-outline-primary btn-sm" href="{{ route('payroll-periods.index') }}">Create a payroll period <i class="ti ti-arrow-right ms-1"></i></a></div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-xxl-4 col-xl-5">
            <div class="card pro-surface-card h-100">
                <div class="card-body d-flex flex-column">
                    <div class="pro-card-heading"><div><span class="pro-overline">CURRENT CYCLE</span><h3>Latest payroll period</h3><p>Draft or finalized record</p></div><span class="pro-card-heading-icon orange"><i class="ti ti-wallet"></i></span></div>
                    @if($latestPeriod)
                        <div class="pro-period-label">{{ $latestPeriod->label }} <span class="badge status-{{ $latestPeriod->status }}">{{ ucfirst($latestPeriod->status) }}</span></div>
                        <div class="pro-period-dates"><i class="ti ti-calendar"></i> {{ $latestPeriod->starts_on->format('M d, Y') }} – {{ $latestPeriod->ends_on->format('M d, Y') }}</div>
                        <div class="pro-amount-stack mt-3"><div><span>Gross pay</span><strong>{{ \App\Support\Money::display($grossCents) }}</strong></div><div><span>Entered deductions</span><strong>{{ \App\Support\Money::display($deductionsCents) }}</strong></div><div class="pro-amount-total"><span>Net pay</span><strong>{{ \App\Support\Money::display($grossCents - $deductionsCents) }}</strong></div></div>
                        <p class="pro-period-note mt-auto">{{ $latestPeriod->entries_count }} manual payroll {{ $latestPeriod->entries_count == 1 ? 'entry' : 'entries' }} · Finalized does not mean paid.</p>
                        <a class="btn btn-primary w-100 mt-3" href="{{ route('payroll-periods.show', $latestPeriod) }}">Open this period <i class="ti ti-arrow-right ms-1"></i></a>
                    @else
                        <div class="pro-empty-small my-auto"><span class="pro-empty-icon"><i class="ti ti-file-invoice"></i></span><h4>No payroll period yet</h4><p>Create a draft period to get started with manual payroll entries.</p></div>
                        <a class="btn btn-primary w-100 mt-3" href="{{ route('payroll-periods.index') }}">Create a period <i class="ti ti-arrow-right ms-1"></i></a>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <section class="row g-3 g-xl-4" aria-label="Recent activity">
        <div class="col-xxl-8 col-xl-7">
            <div class="card pro-surface-card">
                <div class="card-body">
                    <div class="pro-card-heading"><div><span class="pro-overline">PAYROLL RECORDS</span><h3>Recent payroll entries</h3><p>Latest entries in your most recent payroll period</p></div><a class="btn btn-outline-primary btn-sm" href="{{ route('reports.index') }}">View reports <i class="ti ti-arrow-right ms-1"></i></a></div>
                    <div class="table-responsive"><table class="table pro-table mb-0 align-middle"><thead><tr><th scope="col">Employee</th><th scope="col">Employee ID</th><th scope="col" class="text-end">Net amount</th></tr></thead><tbody>
                    @forelse($latestEntries as $entry)
                        <tr><td><div class="pro-person-cell"><span class="pro-person-avatar">{{ strtoupper(mb_substr($entry->employee->full_name, 0, 1)) }}</span><strong>{{ $entry->employee->full_name }}</strong></div></td><td class="text-muted">{{ $entry->employee->employee_number }}</td><td class="text-end pro-table-money">{{ \App\Support\Money::display($entry->net_pay_cents) }}</td></tr>
                    @empty
                        <tr><td colspan="3"><div class="pro-table-empty"><i class="ti ti-file-off"></i><strong>No entries recorded yet</strong><span>Payroll details will appear here once entered.</span></div></td></tr>
                    @endforelse
                    </tbody></table></div>
                </div>
            </div>
        </div>
        <div class="col-xxl-4 col-xl-5">
            <div class="card pro-surface-card">
                <div class="card-body">
                    <div class="pro-card-heading"><div><span class="pro-overline">QUICK ACCESS</span><h3>Manage your workspace</h3><p>Continue where you need to work</p></div></div>
                    <div class="pro-action-list">
                        <a href="{{ route('employees.create') }}"><span class="pro-action-icon"><i class="ti ti-user-plus"></i></span><span><strong>Add an employee</strong><small>Create a workforce record</small></span><i class="ti ti-chevron-right"></i></a>
                        <a href="{{ route('attendance.index') }}"><span class="pro-action-icon green"><i class="ti ti-calendar-plus"></i></span><span><strong>Record attendance</strong><small>Log a daily status</small></span><i class="ti ti-chevron-right"></i></a>
                        <a href="{{ route('payroll-periods.index') }}"><span class="pro-action-icon orange"><i class="ti ti-cash"></i></span><span><strong>Manage payroll</strong><small>Create or review a draft</small></span><i class="ti ti-chevron-right"></i></a>
                    </div>
                    <p class="pro-action-disclaimer"><i class="ti ti-info-circle"></i> This prototype uses manual pay entries. Statutory deductions are not automated.</p>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection
