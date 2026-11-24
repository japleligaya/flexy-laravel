@extends('layouts.app')
@section('title', 'Salary rate history | PayrollGo')
@section('page-title', 'Salary configuration')
@section('content')
<div class="container-fluid pro-page pro-admin-v5 pro-phase6">
  <div class="pro-page-heading"><div><p class="eyebrow">EMPLOYEES / COMPENSATION</p><h1>Salary rate history</h1><p>{{ $employee->full_name }} · {{ $employee->employee_number }}</p></div><a class="btn btn-outline-secondary" href="{{ route('employees.edit', $employee) }}"><i class="ti ti-arrow-left me-1"></i> Employee profile</a></div>
  <div class="alert alert-info">Effective-dated salary history is the only payroll rate source. Monthly and daily rates support batch generation under confirmed company policy. Legacy reference: {{ \App\Support\Money::display((int)$employee->monthly_salary_cents) }}.</div>
@if($employee->salaryWarning())<div class="alert alert-warning">{{ $employee->salaryWarning() }}</div> @endif 
@if($current=$employee->currentRate())<p><strong>Current authoritative pay: {{ \App\Support\Money::display((int)$current->amount_cents) }} / {{ $current->basis }}</strong> ? effective {{ $current->effective_on->toDateString() }}</p> @endif 
  <div class="row g-3 g-xl-4">
    <div class="col-lg-5"><section class="card pro-module-card"><div class="card-body">
      <div class="pro-section-heading"><div><span class="pro-overline">VERSIONED COMPENSATION</span><h2>Add effective salary rate</h2><p>Historical records remain available for payroll audit.</p></div></div>
      <form method="POST" action="{{ route('employees.salary-rates.store', $employee) }}" data-pro-submit-lock>@csrf
        <div class="mb-3"><label class="form-label" for="salary_effective">Effective date *</label><input id="salary_effective" class="form-control @error('effective_on') is-invalid @enderror" name="effective_on" type="date" value="{{ old('effective_on') }}" required>@error('effective_on')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="mb-3"><label class="form-label" for="salary_basis">Salary basis *</label><select id="salary_basis" class="form-select" name="basis" required><option value="daily" @selected(old('basis') === 'daily')>Daily</option><option value="monthly" @selected(old('basis','monthly') === 'monthly')>Monthly</option></select></div>
        <div class="mb-3"><label class="form-label" for="salary_amount">Rate (PHP) *</label><div class="input-group"><span class="input-group-text">₱</span><input id="salary_amount" class="form-control @error('amount') is-invalid @enderror" name="amount" type="number" min="0.01" step="0.01" value="{{ old('amount') }}" placeholder="Enter amount for selected salary basis" required></div>@error('amount')<div class="text-danger small">{{ $message }}</div>@enderror</div>
        <div class="mb-3"><label class="form-label" for="salary_reason">Reason / reference *</label><textarea id="salary_reason" name="reason" class="form-control" minlength="3" maxlength="300" required placeholder="Initial daily rate, salary adjustment, etc.">{{ old('reason') }}</textarea>@error('reason')<div class="text-danger small">{{ $message }}</div>@enderror</div>
        <button type="submit" class="btn btn-primary w-100"><i class="ti ti-plus me-1"></i> Save new rate</button>
      </form>
    </div></section></div>
    <div class="col-lg-7"><section class="card pro-module-card"><div class="card-body">
      <div class="pro-section-heading"><div><span class="pro-overline">CHANGE HISTORY</span><h2>Saved salary versions</h2><p>Each rate applies from its effective date until the next version.</p></div></div>
      <div class="table-responsive pro-table-wrap"><table class="table pro-data-table align-middle mb-0"><thead><tr><th>Effective on</th><th>Basis</th><th class="text-end">Amount</th><th>Reason</th></tr></thead><tbody>
        @forelse($rates as $rate)<tr><td class="pro-tabular">{{ $rate->effective_on->format('M d, Y') }}</td><td><span class="badge status-draft">{{ ucfirst($rate->basis) }}</span></td><td class="text-end pro-tabular"><strong>{{ \App\Support\Money::display((int) $rate->amount_cents) }}</strong></td><td>{{ $rate->reason }}<small class="d-block text-muted">Recorded by {{ $rate->author?->name ?? 'Unknown user' }}</small></td></tr>
        @empty<tr><td colspan="4"><div class="pro-empty-state"><i class="ti ti-calendar-dollar"></i><h3>No effective rates saved</h3><p>Add one above before using attendance-derived payroll previews.</p></div></td></tr> @endforelse 
      </tbody></table></div>
    </div></section></div>
  </div>
</div>
@endsection
