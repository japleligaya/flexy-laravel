@extends('layouts.app')
@section('title', 'Attendance payroll preview | PayrollGo')
@section('page-title', 'Review attendance-based draft')
@section('content')
<div class="container-fluid pro-page pro-admin-v5 pro-phase6">
  <div class="pro-page-heading"><div><p class="eyebrow">PAYROLL / REVIEW FIRST</p><h1>Attendance draft preview</h1><p>{{ $period->label }} · {{ $period->starts_on->format('M d') }} – {{ $period->ends_on->format('M d, Y') }}</p></div><a class="btn btn-outline-secondary" href="{{ route('payroll-periods.show', $period) }}"><i class="ti ti-arrow-left me-1"></i> Back to period</a></div>
  <div class="alert alert-warning" role="note"><strong>Provisional calculations.</strong> Only PRESENT dates with effective DAILY salary rates are included. Unrecorded days are not treated as absent; leave is not assumed paid. Monthly wages, overtime, penalties, holiday rules, and statutory deductions are not calculated. Existing entries will never be overwritten.</div>
  <section class="card pro-module-card mb-4"><div class="card-body">
    <div class="pro-section-heading"><div><span class="pro-overline">CHECK THE DATA</span><h2>Employee calculations</h2><p>Check rates and attendance before generating any entries.</p></div><span class="pro-count-chip">{{ collect($rows)->where('eligible', true)->count() }} eligible</span></div>
    <div class="table-responsive pro-table-wrap"><table class="table pro-data-table align-middle mb-0"><thead><tr><th>Employee</th><th class="text-end">Present</th><th class="text-end">Absent</th><th class="text-end">Leave</th><th class="text-end">Suggested base</th><th>Status</th></tr></thead><tbody>
      @forelse($rows as $row)<tr><td><strong>{{ $row['employee']->full_name }}</strong><small class="d-block text-muted">{{ $row['employee']->employee_number }}</small></td><td class="text-end">{{ $row['present_days'] }}</td><td class="text-end">{{ $row['absent_days'] }}</td><td class="text-end">{{ $row['leave_days'] }}</td><td class="text-end pro-tabular"><strong>{{ \App\Support\Money::display((int) $row['base_pay_cents']) }}</strong></td><td>@if($row['eligible'])<span class="badge status-active">Eligible draft</span>@else<span class="text-muted small">{{ $row['reason'] }}</span>@endif</td></tr>
      @empty<tr><td colspan="6" class="text-center py-4">No active employees yet.</td></tr>@endforelse
    </tbody></table></div>
    @if($period->isDraft() && collect($rows)->where('eligible', true)->isNotEmpty())
      <form method="POST" action="{{ route('payroll-periods.attendance-drafts', $period) }}" class="pro-phase6-review mt-4" data-pro-submit-lock>@csrf
        <label class="form-check"><input class="form-check-input" type="checkbox" name="reviewed" value="1" required><span class="form-check-label">I reviewed the calculated daily-rate amounts and understand that these are editable DRAFT values, not final payslips or statutory payroll.</span></label>
        <button type="submit" class="btn btn-primary mt-3"><i class="ti ti-check me-1"></i> Generate eligible draft entries</button>
      </form>
    @elseif(!$period->isDraft())
      <div class="alert alert-info mt-3 mb-0">This period is locked. Preview is read-only.</div>
    @else<div class="alert alert-info mt-3 mb-0">No new daily-rate entries are eligible. Add salary rates or attendance records, or use manual entries.</div>@endif
  </div></section>
</div>
@endsection
