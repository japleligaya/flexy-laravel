@extends('layouts.app')
@section('title',$period->label.' | PayrollGo')
@section('page-title','Payroll batch review')
@section('content')
@php
  $presentations=$entries->mapWithKeys(fn($entry)=>[$entry->id=>\App\Support\PayrollExperience::entry($entry,$staleIds->contains($entry->id))]);
  $ready=$entries->filter(fn($e)=>$presentations[$e->id]['ready']);
  $missing=$eligible->whereNotIn('id',$entries->pluck('employee_id'));
  $filter=request('readiness','all');
  $isSimulation=($policy['compliance']??null)==='demo'||$presentations->contains(fn($p)=>$p['demo']);
  $completeAmounts=$entries->isNotEmpty()&&$missing->isEmpty()&&$ready->count()===$entries->count();
  $canSubmit=$completeAmounts&&$entries->whereNull('reviewed_at')->isEmpty();
  $workflowStep=!$policy?1:($entries->isEmpty()?2:($period->isDraft()?3:4));
  $batchNetLabel=$isSimulation?'Simulated net pay':(in_array($period->status,['finalized','paid'])?'Net pay':'Estimated net pay');
@endphp
<div class="container-fluid pro-page pro-admin-v5 payroll-experience" id="payroll-help">
  <div class="pro-page-heading"><div><p class="eyebrow">PAYROLL / {{ $isSimulation?'SIMULATION':'REVIEW' }}</p><h1>{{ $period->label }}</h1><p>Pay dates: <strong>{{ $period->starts_on->format('M d, Y') }} – {{ $period->ends_on->format('M d, Y') }}</strong> · <strong class="payroll-state">{{ ucfirst($period->status) }}</strong></p></div><a class="btn btn-outline-secondary" href="{{ route('payroll-periods.index') }}">All periods</a></div>
  @include('payroll-periods.workflow-steps',['workflowStep'=>$workflowStep])
  @if($isSimulation)<div class="alert alert-warning"><strong>Simulation only — not a real payment.</strong> Government deductions are excluded. All amounts below are practice results. Actual payment recording is disabled.</div> @endif
  @if(!$policy)
    <section class="card p-4 mb-4 payroll-next"><h2 class="h4">Start with your company’s pay rules</h2><p>Before we calculate pay, confirm the pay schedule, normal workdays and how to handle part of a month. You do not need to understand the technical codes below.</p>
      @can('settings')<a class="btn btn-primary align-self-start" href="{{ route('payroll-setup',['return_period'=>$period->id]) }}">Set Up Payroll</a>@else<p>Ask your Super Admin to complete Payroll Setup, then return here.</p>@endcan
      @can('payroll')<p class="mt-3 mb-0">Want to see an example first? <a href="{{ route('payroll-preview',['starts_on'=>$period->starts_on->toDateString(),'ends_on'=>$period->ends_on->toDateString()]) }}">Try a simulation without saving payroll</a>.</p>@endcan
    </section>
  @endif
  @if($missing->isNotEmpty())<p class="alert alert-info">{{ $missing->count() }} employee(s) still need a payroll calculation. Generate Payroll to include everyone eligible for these dates; existing entries will be kept.</p> @endif
  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card p-3 h-100"><strong>{{ $entries->count() }} / {{ $eligible->count() }}</strong><span>Employees calculated</span></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3 h-100"><strong>{{ $ready->count() }} ready · {{ $entries->count()-$ready->count() }} need attention</strong><span>{{ $entries->whereNotNull('reviewed_at')->count() }} reviewed</span></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3 h-100"><strong>{{ \App\Support\Money::display($grossCents) }}</strong><span>{{ $completeAmounts?'Total earnings':'Earnings calculated so far' }}</span></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3 h-100"><strong>{{ $completeAmounts?\App\Support\Money::display($netCents):'Not available yet' }}</strong><span>{{ $batchNetLabel }}</span><small class="payroll-help">{{ $isSimulation?'Excludes government deductions':($completeAmounts?'Payment is recorded separately':'Employee or deduction checks are still incomplete') }}</small></div></div>
  </div>
  <div class="payroll-toolbar mb-4">
    @if($period->isDraft()&&$policy) @can('payroll')<form method="POST" action="{{ route('payroll-engine.generate',$period) }}" data-pro-submit-lock>@csrf<button class="btn btn-primary" aria-label="Generate payroll for all eligible employees">Generate Payroll</button></form>@endcan @endif
    <form method="GET"><label class="visually-hidden" for="readiness">Show employees</label><select id="readiness" name="readiness" class="form-select" onchange="this.form.submit()"><option value="all" @selected($filter==='all')>All employees</option><option value="needs-review" @selected($filter==='needs-review')>Needs attention</option><option value="ready" @selected($filter==='ready')>Ready to review</option></select></form>
    @can('settings')<a href="{{ route('payroll-setup',['return_period'=>$period->id]) }}" class="btn btn-outline-secondary">Review setup</a>@endcan
    @can('export')<details><summary>More actions</summary><a class="btn btn-outline-primary" href="{{ route('reports.payroll.csv',$period) }}">Export CSV</a></details>@endcan
  </div>
  @if($period->isDraft()) @can('payroll')
    @php $unreviewedReady=$ready->whereNull('reviewed_at'); @endphp
    @if($unreviewedReady->isNotEmpty())<section class="card p-3 mb-4"><h2 class="h5">Review the ready employees together</h2><p>{{ $unreviewedReady->count() }} employees have completed the required checks. Confirm the pay summaries and notes, then focus on employees who need attention.</p><details><summary>Review notes for ready employees</summary>@foreach($unreviewedReady as $e)<p><strong>{{ $e->employee->full_name }}</strong>: {{ implode(' ', $presentations[$e->id]['warnings'])?:'No additional notes.' }}</p>@endforeach</details><form method="POST" action="{{ route('payroll-engine.review-ready',$period) }}">@csrf<input name="confirmed_count" type="hidden" value="{{ $unreviewedReady->count() }}"><input name="confirmed_net" type="hidden" value="{{ $unreviewedReady->sum(fn($e)=>$e->net_pay_cents) }}"><label class="form-check"><input name="acknowledged" type="checkbox" class="form-check-input" value="1" required>I checked these employees’ pay summaries and review notes.</label><button class="btn btn-outline-primary mt-2">Mark all ready employees reviewed</button></form></section> @endif
  @endcan @endif
  @forelse($entries as $entry)
    @php $presentation=$presentations[$entry->id];$calc=$presentation['calc'];$isReady=$presentation['ready']; @endphp
    @continue(($filter==='ready'&&!$isReady)||($filter==='needs-review'&&$isReady))
    <section class="card payroll-card {{ $isReady?'':'needs-review' }} mb-3" data-entry="{{ $entry->id }}"><div class="card-body">
      <div class="d-flex gap-2 flex-wrap justify-content-between"><div><h2 class="h5">{{ $entry->snapshot['employee']['name']??$entry->employee->full_name }}</h2><p>{{ $entry->employee->employee_number }} · {{ $entry->reviewed_at?'Reviewed':'Waiting for review' }}</p></div><strong>{{ $isReady?'Ready':'Needs attention' }}</strong></div>
      @include('payroll-periods.pay-summary',['payEntry'=>$entry])
      @include('payroll-periods.issues',['issueGroups'=>$presentation['groups']])
      @if($presentation['warnings'])<details><summary>Review notes</summary><ul>@foreach($presentation['warnings'] as $note)<li>{{ $note }}</li>@endforeach</ul></details> @endif
      <details><summary>How computed — pay dates and amounts</summary><p>Base pay uses the saved salary rate for each included date. Extra earnings are added; confirmed employee deductions are subtracted. Employer contributions are not taken from the employee’s pay.</p><div class="table-responsive payroll-lines"><table class="table"><thead><tr><th>Pay item / date</th><th>PHP</th></tr></thead><tbody>@foreach($calc['lines']??[] as $line) @continue($line['type']==='employer')<tr><td>{{ $line['label'] }}</td><td>{{ \App\Support\Money::input($line['amount_cents']) }}</td></tr>@endforeach</tbody></table></div></details>
      @include('payroll-periods.entry-actions')
      @include('payroll-periods.technical-details',['payEntry'=>$entry])
    </div></section>
  @empty
    <section class="card p-4 mb-3"><h2 class="h4">Your payroll starts here</h2><p>{{ $policy?'Select Generate Payroll to use saved salaries for all eligible employees. We will tell you what needs attention before you can approve anything.':'Complete Payroll Setup first, or try a read-only simulation to understand salary and partial-month pay.' }}</p><p class="payroll-help mb-0">You do not need to type everyone’s base pay. Keep employee salary history and required attendance up to date.</p></section>
  @endforelse
  @if($entries->isNotEmpty()&&(($filter==='ready'&&$ready->isEmpty())||($filter==='needs-review'&&$ready->count()===$entries->count())))<p class="alert alert-info">No employees match this filter. Choose “All employees” to see the complete batch.</p>@endif
  @if($excluded->isNotEmpty())<details class="card p-3 mb-3"><summary>Why are some employees not included?</summary><p>These employees joined after the last pay date in this period.</p>@foreach($excluded as $e)<p>{{ $e->full_name }} · joined {{ $e->hired_on->format('M d, Y') }}</p>@endforeach</details>@endif
  @include('payroll-periods.batch-controls')
</div>
@endsection
