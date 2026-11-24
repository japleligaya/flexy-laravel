<div class="payroll-source">
  <strong>Salary source</strong>
  @if($presentation['manual'])<p class="mb-1">Manually adjusted pay: {{ $payEntry->notes }}. Saved rate references are shown below.</p>@endif
  @forelse($presentation['rates'] as $rate)<p class="mb-1">Saved salary: <strong>{{ \App\Support\Money::display((int)$rate->amount_cents) }} {{ $rate->basis==='monthly'?'per month':'per workday' }}</strong> · applies from {{ $rate->effective_on->format('M d, Y') }}</p>
  @empty<p class="mb-1">No confirmed dated salary source is available for this calculation.</p> @endforelse
  <p class="payroll-help mb-0">{{ $presentation['days'] }} paid date(s) included. @if(($presentation['calc']['policy']['proration']??null)==='calendar_days')Partial-month method: calendar days in the month.@elseif(($presentation['calc']['policy']['proration']??null)==='scheduled_workdays')Partial-month method: normal workdays in the month.@endif</p>
</div>
<dl class="payroll-money mb-0">
  <div><dt>Base Pay <details class="payroll-inline-help"><summary aria-label="What is Base Pay?">?</summary><p>Pay for the included employment or work dates using the saved salary. Extra earnings are shown separately.</p></details></dt><dd>{{ \App\Support\Money::display($payEntry->base_pay_cents) }}</dd></div>
  <div><dt>Extra earnings</dt><dd>{{ \App\Support\Money::display($payEntry->overtime_pay_cents+$payEntry->allowances_cents) }}<small class="d-block payroll-help">Approved extra hours and other earnings</small></dd></div>
  <div><dt>Total earnings</dt><dd>{{ \App\Support\Money::display($payEntry->gross_pay_cents) }}</dd></div>
  <div><dt>{{ $presentation['demo']?'Deductions in this simulation':($payEntry->snapshot?'Deductions':'Deductions so far') }} <details class="payroll-inline-help"><summary aria-label="What are deductions?">?</summary><p>Amounts taken from earnings, including confirmed contributions, income tax and documented deductions. Missing government checks do not mean a confirmed zero.</p></details></dt><dd>{{ \App\Support\Money::display($payEntry->deductions_cents) }} @if(!$presentation['demo']&&!$presentation['deductionsComplete'])<small class="d-block payroll-help">Incomplete — government amounts still need checking</small>@endif</dd></div>
  <div><dt>{{ $presentation['netLabel'] }} <details class="payroll-inline-help"><summary aria-label="What is net pay?">?</summary><p>What remains after deductions. Draft amounts are estimates. Simulations exclude government deductions. Finalized does not mean Paid.</p></details></dt><dd><strong>{{ $presentation['netAvailable']?\App\Support\Money::display($payEntry->net_pay_cents):'Not available yet' }}</strong></dd></div>
</dl>
@if($presentation['demo'])<p class="payroll-help mt-2">Simulation only. Government deductions are excluded, so this is not the amount to pay an employee.</p>
@elseif(!$presentation['netAvailable'])<p class="payroll-help mt-2">The calculation is incomplete. Resolve the items below before relying on a take-home amount.</p>
@elseif(!$payEntry->snapshot)<p class="payroll-help mt-2">This is an estimate for review. Approval and finalization are still required; payment is recorded separately.</p>@endif
