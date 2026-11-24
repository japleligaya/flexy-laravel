@extends('layouts.app')
@section('title','Payroll setup | PayrollGo')
@section('page-title','Payroll setup')
@section('content')
<div class="container-fluid pro-page pro-admin-v5 payroll-experience">
  <div class="pro-page-heading"><div><p class="eyebrow">LET’S GET PAYROLL READY</p><h1>Set up payroll</h1><p>Answer a few questions about your company. If you are unsure, check with the person responsible for payroll before choosing.</p></div><a class="btn btn-outline-secondary" href="{{ route('payroll-periods.index') }}">Back to payroll</a></div>
  @include('payroll-periods.workflow-steps',['workflowStep'=>1])
  <div class="alert alert-info">Want to understand the numbers first? <a href="{{ route('payroll-preview') }}">Try a read-only simulation</a>. It does not save company rules, create payroll or record payments.</div>
  <form method="POST" action="{{ route('payroll-settings.store') }}" class="card p-3 p-md-4" data-payroll-wizard>
    @csrf
    <input type="hidden" name="setup_wizard" value="1">
    @if($returnPeriod)<input type="hidden" name="return_period" value="{{ $returnPeriod }}"> @endif
    <p class="payroll-help" data-wizard-progress role="status" aria-live="polite">Complete each section, then confirm your choices.</p>
    <fieldset data-setup-step>
      <legend>Company and pay schedule</legend>
      <p>These choices determine which dates each payroll covers. They do not send money to employees.</p>
      <div class="row g-3">
        <div class="col-md-6"><label for="setup-company" class="form-label">What is your company’s name?</label><input id="setup-company" name="company_name" class="form-control @error('company_name') is-invalid @enderror" value="{{ old('company_name',$policy['company_name']??'') }}" maxlength="200" required data-summary-label="Company"></div>
        <div class="col-md-6"><label for="setup-effective" class="form-label">When should these rules start?</label><input id="setup-effective" name="effective_on" type="date" class="form-control @error('effective_on') is-invalid @enderror" value="{{ old('effective_on',$policy['effective_on']??'') }}" required data-summary-label="Rules start"><p class="payroll-help">Choose the first day of the agreed month. We will not invent a historical start date.</p></div>
      </div>
      @php $scheduleChoices=[
        'frequency'=>['How often do you prepare payroll?','A pay period is the set of dates covered by one payroll.',['monthly'=>'Once a month: 1st to the last day','semi_monthly'=>'Twice a month: 1st–15th and 16th–last day']],
      ]; @endphp
      @foreach($scheduleChoices as $key=>[$label,$help,$options])
        @include('payroll-settings.setup-question')
      @endforeach
    </fieldset>
    <fieldset data-setup-step>
      <legend>Workdays and partial months</legend>
      <p>For someone joining partway through a month, tell us how your company calculates the part they should be paid.</p>
      <fieldset class="mb-3"><legend class="h6">Which days are normally workdays?</legend><div class="d-flex gap-3 flex-wrap">
        @foreach([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'] as $day=>$name)
        <label class="form-check"><input name="workdays[]" value="{{ $day }}" type="checkbox" class="form-check-input @error('workdays') is-invalid @enderror" @checked(in_array($day,old('workdays',$policy['workdays']??[]))) data-summary-label="Workday" data-summary-value="{{ $name }}">{{ $name }}</label>
        @endforeach
      </div><p class="payroll-help">Select your actual schedule. Daily-paid employees need attendance for the scheduled dates.</p></fieldset>
      @php $workChoices=[
        'proration'=>['How do you pay a monthly employee for part of a month?','This also affects unpaid days. Use the method agreed by your company; neither choice is selected for you.',[
          'calendar_days'=>'Use all calendar days in that month (including weekends)',
          'scheduled_workdays'=>'Use only the normal workdays selected above']],
        'monthly_attendance'=>['Must monthly-paid staff have attendance recorded each workday?','Daily-paid staff always need attendance. Recorded absences and unpaid leave can reduce pay under the chosen policy.',[
          'required'=>'Yes, check every scheduled workday before payroll',
          'not_required'=>'No, use employment dates; still account for recorded unpaid days']],
        'paid_leave'=>['How should recorded leave be treated?','Confirm company entitlement and any applicable leave requirements with your payroll specialist.',[
          'approved_only'=>'Pay leave only when it is explicitly approved as paid',
          'unpaid'=>'Treat recorded leave as unpaid under our confirmed policy']],
      ]; @endphp
      @foreach($workChoices as $key=>[$label,$help,$options])
        @include('payroll-settings.setup-question')
      @endforeach
      <label for="setup-hours" class="form-label">How many regular hours are in a workday?</label><input id="setup-hours" name="hours_per_day" type="number" min="1" max="24" class="form-control @error('hours_per_day') is-invalid @enderror" value="{{ old('hours_per_day',$policy['hours_per_day']??'') }}" required data-summary-label="Hours per workday">
      @php $key='ot_approval';$label='How should extra hours be included?';$help='The system pays only explicitly approved overtime. Missing time logs never create extra hours automatically.';$options=['required'=>'Require approved overtime minutes before including extra pay']; @endphp
      @include('payroll-settings.setup-question')
      <details><summary>Overtime rate for monthly-paid staff (if needed)</summary><label for="setup-ot-divisor" class="form-label">Agreed number of days used to calculate a daily overtime rate</label><input id="setup-ot-divisor" name="monthly_ot_divisor" type="number" min="1" max="366" class="form-control @error('monthly_ot_divisor') is-invalid @enderror" value="{{ old('monthly_ot_divisor',$policy['monthly_ot_divisor']??'') }}"><p class="payroll-help">Leave this blank if you have not confirmed it. Monthly overtime will then need review, rather than using a guessed rate.</p></details>
    </fieldset>
    <fieldset data-setup-step>
      <legend>Deductions and approval</legend>
      <p>Government deductions affect take-home pay. Saving these choices does not certify that every government rule or employee classification has been verified.</p>
      @php $controlChoices=[
        'compliance'=>['How should government deductions be checked?','Both choices keep unresolved deductions visible. An authorized specialist must handle any supported manual exception.',[
          'strict'=>'Require verified calculations before payroll can be approved',
          'manual'=>'Allow an authorized specialist to enter amounts with a source and reason']],
        'contribution_timing'=>['When are monthly government contributions taken from pay?','For twice-monthly payroll, the later cutoff checks what was already included earlier in the month.',[
          'monthly'=>'With the monthly payroll',
          'last_cutoff'=>'With the second payroll of the month',
          'split'=>'Split across the two payrolls and reconcile the second']],
        'labor_review'=>['Has someone checked holiday and special-pay coverage?','You can choose “Not yet” and save setup. A specialist must resolve that review before real payroll can be approved.',[
          'unverified'=>'Not yet — keep real payroll blocked for review',
          'ordinary_only'=>'Yes — ordinary days checked; special pay will be documented separately']],
        'separate_approver'=>['Must a different person approve payroll?','This preserves your chosen approval policy. Only users with the required server permissions can approve or finalize.',[
          '1'=>'Yes, require a different authorized approver',
          '0'=>'Our confirmed policy allows the same authorized person']],
      ]; @endphp
      @foreach($controlChoices as $key=>[$label,$help,$options])
        @include('payroll-settings.setup-question')
      @endforeach
    </fieldset>
    <fieldset data-setup-step>
      <legend>Check and confirm your choices</legend>
      <p>No real company policy has been assumed. Please confirm these choices with the person responsible for payroll.</p>
      <ul data-setup-summary><li>Review the answers in the sections above before saving.</li></ul>
      <label for="setup-reason" class="form-label">Who confirmed these choices, and why are you saving them?</label><textarea id="setup-reason" name="reason" class="form-control @error('reason') is-invalid @enderror" minlength="10" maxlength="500" required>{{ old('reason') }}</textarea>
      <label class="form-check mt-3"><input name="setup_confirmed" type="checkbox" value="1" class="form-check-input @error('setup_confirmed') is-invalid @enderror" required>I confirm that these are our agreed company choices. Unresolved employee or compliance issues will still block approval.</label>
    </fieldset>
    <div class="d-flex gap-2 mt-4" data-wizard-controls hidden><button type="button" class="btn btn-outline-secondary" data-wizard-back>Back</button><button type="button" class="btn btn-primary" data-wizard-next>Continue</button></div>
    <div class="mt-3"><button type="submit" class="btn btn-primary" data-wizard-save>Save setup and go to payroll</button></div>
  </form>
</div>
@endsection
