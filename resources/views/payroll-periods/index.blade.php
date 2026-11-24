@extends('layouts.app')
@section('title', 'Payroll periods | PayrollGo')
@section('page-title', 'Payroll periods')
@section('content')
<div class="container-fluid pro-page pro-admin-v5 payroll-experience">
  <div class="pro-page-heading"><div><p class="eyebrow">PAYROLL / CYCLES</p><h1>Payroll periods</h1><p>Set up your company rules, choose pay dates, then generate and review employee pay.</p></div><span class="pro-page-chip"><i class="ti ti-shield-lock"></i> Guided payroll</span></div>
  <div class="pro-module-intro mb-4"><div class="pro-module-symbol"><i class="ti ti-wallet"></i></div><div><strong>Set Up - Generate Payroll - Review - Approve / Finalize</strong><span>Payroll uses saved salary history. After approval and finalization, payslips can be downloaded. Payment is recorded separately.</span></div><div class="pro-intro-count"><strong>{{ $periods->total() }}</strong><span>Periods</span></div></div>
  @include('payroll-periods.workflow-steps',['workflowStep'=>$hasPolicy?2:1])
<section class="card p-3 mb-4"><h2 class="h5">{{ $hasPolicy?'Your next step: choose a pay period':'First, confirm how your company pays' }}</h2><p>{{ $hasPolicy?'Open or create a pay period below, then select Generate Payroll. Any missing information will have a clear Fix action.':'Guided setup explains pay frequency, workdays and deductions without assuming your company policies.' }}</p><div class="d-flex flex-wrap gap-2">@can('settings')<a class="btn btn-primary" href="{{ route('payroll-setup') }}">{{ $hasPolicy?'Review payroll setup':'Set Up Payroll' }}</a>@endcan @can('payroll')<a class="btn btn-outline-primary" href="{{ route('payroll-preview') }}">Try a simulation</a>@endcan</div></section>
<div class="row g-3 g-xl-4">
    <div class="col-lg-4"><section class="card pro-module-card" aria-labelledby="newPeriodTitle"><div class="card-body"><div class="pro-section-heading"><div><span class="pro-overline">START A CYCLE</span><h2 id="newPeriodTitle">Create draft period</h2><p>Dates cannot overlap an active cycle. Cancelled drafts do not block new periods.</p></div><span class="pro-section-icon"><i class="ti ti-calendar-plus"></i></span></div>
      <form method="POST" action="{{ route('payroll-periods.store') }}" data-pro-submit-lock>@csrf
        <div class="mb-3"><label class="form-label" for="period_label">Period label *</label><input id="period_label" name="label" class="form-control @error('label') is-invalid @enderror" value="{{ old('label') }}" maxlength="255" placeholder="e.g. November 1–15, 2026" required>@error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="row g-3 mb-3"><div class="col-sm-6"><label class="form-label" for="period_start">Start date *</label><input id="period_start" name="starts_on" type="date" class="form-control @error('starts_on') is-invalid @enderror" value="{{ old('starts_on') }}" required>@error('starts_on')<div class="invalid-feedback">{{ $message }}</div>@enderror</div><div class="col-sm-6"><label class="form-label" for="period_end">End date *</label><input id="period_end" name="ends_on" type="date" class="form-control @error('ends_on') is-invalid @enderror" value="{{ old('ends_on') }}" required>@error('ends_on')<div class="invalid-feedback">{{ $message }}</div>@enderror</div></div>
        <button type="submit" class="btn btn-primary w-100"><i class="ti ti-plus me-1"></i> Create draft period</button>
      </form>
    </div></section></div>
    <div class="col-lg-8"><section class="card pro-module-card" aria-labelledby="cyclesTitle"><div class="card-body"><div class="pro-section-heading"><div><span class="pro-overline">PAYROLL HISTORY</span><h2 id="cyclesTitle">All payroll cycles</h2><p>Drafts can be reviewed or cancelled; finalized and cancelled cycles remain in history.</p></div></div>
      <div class="table-responsive pro-table-wrap"><table class="table pro-data-table align-middle mb-0"><thead><tr><th>Period</th><th>Date range</th><th class="text-center">Entries</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>
      @forelse($periods as $period)
        <tr><td><strong class="pro-cell-primary">{{ $period->label }}</strong><small class="d-block text-muted">Payroll cycle #{{ $period->id }}</small></td><td class="pro-tabular">{{ $period->starts_on->format('M d') }} – {{ $period->ends_on->format('M d, Y') }}</td><td class="text-center"><span class="pro-count-chip">{{ $period->entries_count }}</span></td><td><span class="badge status-{{ $period->status }}">{{ ucfirst($period->status) }}</span></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('payroll-periods.show', $period) }}">{{ $period->status === 'draft' ? 'Continue' : 'Review' }} <i class="ti ti-arrow-right ms-1"></i></a></td></tr>
      @empty<tr><td colspan="5"><div class="pro-empty-state"><span><i class="ti ti-file-invoice"></i></span><h3>No payroll periods</h3><p>Complete setup, then choose the dates covered by your first payroll. You do not need to type individual base salaries.</p></div></td></tr>@endforelse
      </tbody></table></div>
      @if($periods->hasPages())<div class="pro-pagination">{{ $periods->links() }}</div>@endif
      <div class="pro-disclaimer"><i class="ti ti-alert-circle"></i><div><strong>Drafts require review.</strong> Gross = base + overtime + allowances. Net = gross − entered deductions. Tax, SSS, PhilHealth and Pag-IBIG are not automatically computed.</div></div>
    </div></section></div>
  </div>
</div>
@endsection
