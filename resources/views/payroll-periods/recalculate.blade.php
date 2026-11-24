@extends('layouts.app')
@section('page-title','Preview recalculation')
@section('content')
<div class="container-fluid pro-page payroll-experience"><h1>Preview updated pay · {{ $entry->employee->full_name }}</h1><p>Check the original and updated amounts below. Nothing changes until you confirm. This affects only this unreviewed draft; if employee information changes again, a new preview is required.</p>
<div class="table-responsive"><table class="table"><thead><tr><th>Amount</th><th>Before (PHP)</th><th>After (PHP)</th><th>Change (centavos)</th></tr></thead><tbody>
@foreach(['base_pay_cents'=>'Base','overtime_pay_cents'=>'OT','allowances_cents'=>'Other earnings','deductions_cents'=>'Deductions'] as $key=>$label)<tr><td>{{ $label }}</td><td>{{ \App\Support\Money::input($entry->$key) }}</td><td>{{ \App\Support\Money::input($candidate['totals'][$key]) }}</td><td>{{ $candidate['totals'][$key]-$entry->$key }}</td></tr>@endforeach</tbody></table></div>
@php
 $issueGroups=\App\Support\PayrollExperience::groups($candidate['exceptions']??[],$entry);
 foreach($issueGroups as &$issues)foreach($issues as &$issue)if(str_starts_with($issue['url'],'#'))$issue['url']=route('payroll-periods.show',$entry->payroll_period_id).$issue['url'];
 unset($issues,$issue);
@endphp
@include('payroll-periods.issues')
<details><summary>Technical details: original and updated calculations</summary><h2 class="h5">Before</h2><pre class="text-wrap">{{ json_encode($entry->calculation,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre><h2 class="h5">After</h2><pre class="text-wrap">{{ json_encode($candidate,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
<form method="POST" action="{{ route('payroll-engine.recalculate',$entry) }}">@csrf<input type="hidden" name="preview_hash" value="{{ $hash }}"><label class="form-check"><input type="checkbox" name="confirmed" value="1" class="form-check-input" required>I reviewed the exact before / after values and exceptions.</label><button class="btn btn-primary mt-3">Confirm recalculation</button><a class="btn btn-outline-secondary mt-3" href="{{ route('payroll-periods.show',$entry->payroll_period_id) }}">Back to batch</a></form></div>
@endsection
