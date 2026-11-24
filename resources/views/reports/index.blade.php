@extends('layouts.app')
@section('title', 'Reports | Payroll Go')
@section('page-title', 'Reports & exports')
@section('content')
<div class="container-fluid pro-page pro-admin-v5">
  <div class="pro-page-heading"><div><p class="eyebrow">PAYROLL / REPORTS</p><h1>Reports & exports</h1><p>Review periods and download manually prepared payroll data.</p></div></div>
  <div class="pro-module-intro mb-4"><div class="pro-module-symbol"><i class="ti ti-file-spreadsheet"></i></div><div><strong>Payroll records, ready for review</strong><span>Export each payroll cycle as a CSV file for local reporting and reconciliation.</span></div><div class="pro-intro-count"><strong>{{ $periods->count() }}</strong><span>Available periods</span></div></div>
  <section class="card pro-module-card" aria-labelledby="reportTableTitle"><div class="card-body">
    <div class="pro-section-heading"><div><span class="pro-overline">CSV DOWNLOADS</span><h2 id="reportTableTitle">Payroll period exports</h2><p>Each export includes employee information and recorded pay amounts.</p></div><span class="pro-section-icon"><i class="ti ti-download"></i></span></div>
    <div class="table-responsive pro-table-wrap"><table class="table pro-data-table align-middle mb-0"><thead><tr><th>Period</th><th>Date range</th><th class="text-center">Entries</th><th>Status</th><th class="text-end">Export</th></tr></thead><tbody>
    @forelse($periods as $period)
      <tr><td><strong class="pro-cell-primary">{{ $period->label }}</strong><small class="d-block text-muted">Cycle #{{ $period->id }}</small></td><td class="pro-tabular">{{ $period->starts_on->format('M d') }} – {{ $period->ends_on->format('M d, Y') }}</td><td class="text-center"><span class="pro-count-chip">{{ $period->entries_count }}</span></td><td><span class="badge status-{{ $period->status }}">{{ ucfirst($period->status) }}</span></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('reports.payroll.csv', $period) }}" data-no-fast-nav><i class="ti ti-download me-1"></i> Download CSV</a></td></tr>
    @empty<tr><td colspan="5"><div class="pro-empty-state"><span><i class="ti ti-file-off"></i></span><h3>Nothing to export yet</h3><p>Create a payroll period and add entries before downloading a report.</p><a href="{{ route('payroll-periods.index') }}" class="btn btn-outline-primary btn-sm">Go to payroll periods</a></div></td></tr>@endforelse
    </tbody></table></div>
    <div class="pro-disclaimer"><i class="ti ti-lock"></i><div><strong>Handle payroll exports carefully.</strong> CSV files contain salary information. Share only with authorized staff. This prototype does not yet implement role-specific export access or audit logging.</div></div>
  </div></section>
</div>
@endsection
