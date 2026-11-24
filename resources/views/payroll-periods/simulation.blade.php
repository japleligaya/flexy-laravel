@extends('layouts.app')
@section('title','Payroll simulation | Payroll Pro')
@section('page-title','Payroll simulation')
@section('content')
<div class="container-fluid pro-page pro-admin-v5 payroll-experience">
  <div class="pro-page-heading"><div><p class="eyebrow">LEARN WITHOUT CREATING PAYROLL</p><h1>Try a payroll simulation</h1><p>See how saved salaries and a partial month affect pay before setting up real payroll.</p></div><a class="btn btn-outline-secondary" href="{{ route('payroll-periods.index') }}">Back to payroll</a></div>
  <div class="alert alert-warning"><strong>Simulation only — not a payslip or a payment.</strong> Government deductions are excluded. Your company rules, payroll records and approvals will not be changed.</div>
  <section class="card p-3 p-md-4 mb-4" id="simulation-options"><h2 class="h5">Choose the example you want to try</h2><p class="payroll-help">The visible example choices below are practice assumptions, not saved company policies. We use employees’ existing dated salary rates and employment dates.</p>
  <form method="GET" action="{{ route('payroll-preview') }}" class="row g-3">
    <input name="preview" type="hidden" value="1">
    <div class="col-sm-6"><label for="sim-start" class="form-label">Payroll start date</label><input id="sim-start" name="starts_on" type="date" class="form-control" value="{{ request('starts_on',now()->startOfMonth()->toDateString()) }}" required></div>
    <div class="col-sm-6"><label for="sim-end" class="form-label">Payroll end date</label><input id="sim-end" name="ends_on" type="date" class="form-control" value="{{ request('ends_on',now()->endOfMonth()->toDateString()) }}" required></div>
    <div class="col-md-6"><label for="sim-frequency" class="form-label">Example pay schedule</label><select id="sim-frequency" name="frequency" class="form-select"><option value="monthly" @selected(request('frequency','monthly')==='monthly')>Once a month</option><option value="semi_monthly" @selected(request('frequency')==='semi_monthly')>Twice a month (1–15 / 16–month end)</option></select></div>
    <div class="col-md-6"><label for="sim-proration" class="form-label">For a partial month, count…</label><select id="sim-proration" name="proration" class="form-select"><option value="calendar_days" @selected(request('proration','calendar_days')==='calendar_days')>All calendar days in the month</option><option value="scheduled_workdays" @selected(request('proration')==='scheduled_workdays')>Only the example workdays below</option></select></div>
    <fieldset class="col-12"><legend class="h6">Example normal workdays</legend><div class="d-flex flex-wrap gap-3">@foreach([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $day=>$name)<label class="form-check"><input name="workdays[]" type="checkbox" value="{{ $day }}" class="form-check-input" @checked(in_array($day,request('workdays',[1,2,3,4,5])))>{{ $name }}</label> @endforeach</div></fieldset>
    <div class="col-md-6"><label for="sim-attendance" class="form-label">For monthly staff, should this example require attendance?</label><select id="sim-attendance" name="monthly_attendance" class="form-select"><option value="not_required" @selected(request('monthly_attendance','not_required')==='not_required')>No — use employment dates and recorded unpaid days</option><option value="required" @selected(request('monthly_attendance')==='required')>Yes — check every example workday</option></select><p class="payroll-help">Daily-paid staff still need recorded attendance.</p></div>
    <div class="col-md-6"><label for="sim-hours" class="form-label">Example regular hours per day</label><input id="sim-hours" name="hours_per_day" type="number" min="1" max="24" value="{{ request('hours_per_day',8) }}" required class="form-control"></div>
    <div class="col-12"><details><summary>If this example includes monthly overtime</summary><label for="sim-divisor" class="form-label">Confirmed days used to turn monthly salary into a daily overtime rate</label><input id="sim-divisor" name="monthly_ot_divisor" type="number" min="1" max="366" value="{{ request('monthly_ot_divisor') }}" class="form-control"><p class="payroll-help">Leave blank if unknown. Only approved extra hours and explicitly approved paid leave are eligible; unknown rates remain flagged.</p></details></div>
    <div><button class="btn btn-primary">Preview simulated pay</button></div>
  </form></section>
  @if(request()->boolean('preview'))
    <h2 class="h4">Simulation results</h2><p>{{ request('starts_on') }} to {{ request('ends_on') }}. These amounts are estimates for learning; they cannot be approved, finalized or paid from this preview.</p>
    @forelse($rows as $row)
    @php $employee=$row['employee'];$entry=$row['entry'];$p=$row['presentation']; @endphp
    <section class="card p-3 mb-3 payroll-simulation"><h3 class="h5">{{ $employee->full_name }} <span class="badge text-bg-warning">Simulation</span></h3>
      @include('payroll-periods.pay-summary',['presentation'=>$p,'payEntry'=>$entry])
      @include('payroll-periods.issues',['issueGroups'=>$p['groups']])
      @include('payroll-periods.technical-details',['presentation'=>$p,'payEntry'=>$entry])
    </section>
    @empty<div class="card p-4"><h3 class="h5">No employees match these dates</h3><p>Choose dates that overlap an employee’s employment, and make sure a dated salary has been saved.</p>@can('people')<a class="btn btn-outline-primary" href="{{ route('employees.index') }}">Check employee information</a>@endcan</div>
    @endforelse
  @else <div class="card p-4"><h2 class="h5">An example: someone joins partway through a month</h2><p>Choose their hire month above. The preview will show their saved rate, the paid dates included and the effect of your chosen partial-month method. You do not need to configure government deductions.</p></div> @endif
</div>
@endsection
