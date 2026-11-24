@extends('layouts.app')
@section('page-title','My payslips')
@section('content')
<div class="container-fluid pro-page payroll-experience"><h1>My payslips</h1><p>View or print finalized pay statements for the employee linked to your account. Finalized does not mean Paid.</p>
@forelse($entries as $entry)
@php $simulation=($entry->snapshot['calculation']['policy']['compliance']??null)==='demo'; @endphp
<section class="card p-3 mb-3"><h2 class="h5">{{ $entry->snapshot['period']['label'] }}</h2><p>{{ $entry->snapshot['period']['starts_on'] }} to {{ $entry->snapshot['period']['ends_on'] }}</p>
@if($simulation)<p class="alert alert-warning">Simulation only. Government deductions are excluded; this is not a real payment document.</p>@else<p>Status: {{ ucfirst($entry->payrollPeriod->status) }}. @if($entry->payrollPeriod->status==='finalized')Payment has not yet been fully recorded.@endif</p>@endif
<a href="{{ route('payslips.download',$entry) }}" class="btn btn-primary align-self-start">{{ $simulation?'Download simulation PDF':'Download PDF payslip' }}</a></section>
@empty
<section class="card p-4"><h2 class="h4">Your payslip will appear here after payroll is finalized</h2>
<ol><li>The payroll team sets up company rules and generates your pay.</li><li>They resolve missing information, review the amounts, then obtain approval and finalize.</li><li>A Super Admin links your login to the correct employee. You can then download your own PDF here.</li></ol>
@if(!auth()->user()->employee_id)<p class="alert alert-info">Your account is not linked to an employee yet. Ask your Super Admin to connect it in Payroll settings → Access controls.</p>@else<p>Ask the payroll team whether the pay period has been finalized for your linked employee.</p>@endif
@can('settings')<a class="btn btn-outline-primary align-self-start" href="{{ route('payroll-settings.index') }}#access-controls">Check employee account links</a>@endcan
<p class="payroll-help mt-3 mb-0">Need a walkthrough? Select the “?” button in the corner for the Payroll to My Payslips guide.</p></section>
@endforelse </div>
@endsection
