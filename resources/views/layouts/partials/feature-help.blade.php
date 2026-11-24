@php
  $helpRoute=request()->route()?->getName()??'';
  $helpGuides=[
    'employees'=>['Employee information','Save the employee’s profile and dated salary before generating payroll.',[
      'Enter the employee’s name, employee number and actual hire date.',
      'Save the agreed monthly or daily salary with the date it starts. Later salary changes belong in Salary rate history.',
      'Check employment and payroll classification with HR. Missing information will appear as an item to fix in payroll.',
    ]],
    'departments'=>['Departments','Departments help you organize employees.',[
      'Create a department using a name your company recognizes.',
      'Choose that department on each employee’s profile.',
      'Changing a department does not calculate or pay salary.',
    ]],
    'attendance'=>['Attendance and extra hours','Payroll needs attendance where your company policy requires it.',[
      'Choose the employee and work date, then record Present, Absent or On leave.',
      'Extra hours must be entered and explicitly approved before they can increase pay. Time logs alone do not create overtime.',
      'Ask an authorized approver to confirm paid leave or extra hours. After a change, return to payroll and preview updated amounts.',
    ]],
    'payroll-setup'=>['Set up payroll','Sagutin ang company rules—huwag manghula kung hindi sigurado.',[
      'Confirm the company’s pay schedule, normal workdays and partial-month method.',
      'Choose attendance, overtime, deductions and approval rules with the person responsible for payroll.',
      'Check the summary and save. Saving setup does not remove unresolved employee or government-deduction checks.',
      'Return to Payroll periods, choose dates, then select Generate Payroll.',
    ]],
    'payroll-settings'=>['Payroll settings','Use guided setup for everyday choices. Specialist controls are separate.',[
      'Open Guided payroll setup to confirm company rules in plain English.',
      'Government-rule references and authorized exceptions are for the payroll specialist. Missing rules are never treated as confirmed zero deductions.',
      'In Access controls, a Super Admin can link a user account to its employee so that the employee can see their own payslips.',
    ]],
    'payroll-preview'=>['Try a simulation','Practice only—walang payroll record o payment na ise-save.',[
      'Choose example payroll dates, workdays and how to handle part of a month.',
      'Select Preview simulated pay to use the employees’ saved salaries and hire dates.',
      'Government deductions are excluded. The result is not an amount to pay and cannot be finalized from this screen.',
      'For real payroll, complete company setup and use Payroll periods.',
    ]],
    'payroll'=>['Payroll to payslips','Sundan ang Set Up → Generate Payroll → Review → Approve / Finalize.',[
      'Set Up: a Super Admin confirms company rules. HR confirms employee dates, salaries and required attendance.',
      'Generate Payroll: choose the pay period, then generate employee calculations. Existing entries are preserved.',
      'Review: follow each Fix action. Check paid dates, earnings and deductions. Take-home pay is not final while information is missing.',
      'Approve / Finalize: the required authorized approver checks the completed payroll. A different person may be required by company policy.',
      'After finalization, download the payslip. Employees see their own payslips only when their account is linked to the correct employee.',
      'Record an actual payment separately after funds have been paid. Finalized does not mean Paid.',
    ]],
    'payslips'=>['My payslips','Dito makikita ang finalized payslip ng employee na naka-link sa account mo.',[
      'Payroll must first be generated, reviewed, approved and finalized by the authorized team.',
      'A Super Admin must link your login account to the correct employee in Payroll settings → Access controls.',
      'Select Download PDF to view or print a payslip. A simulation is clearly marked and is not a real payment document.',
      'If the list is empty, ask your payroll team whether payroll is finalized and your account is linked. Payment status is recorded separately.',
    ]],
    'reports'=>['Payroll reports','Use reports to review or export a pay period.',[
      'Choose the correct payroll period before downloading a CSV.',
      'Draft amounts may be incomplete. Check employee and government-deduction issues on the payroll review screen.',
      'Finalization locks the reviewed amounts; it does not record a payment. Simulation exports are not real payroll.',
    ]],
    'dashboard'=>['Where to start','Prepare employee information, then follow the payroll guide.',[
      'Employees: save hire dates and dated salaries.',
      'Attendance: record required workdays and obtain approval for paid leave or extra hours.',
      'Payroll: Set Up → Generate Payroll → Review → Approve / Finalize.',
      'My payslips: finalized payslips appear for the employee linked to the signed-in account.',
    ]],
  ];
  $helpKey=match(true){
    str_starts_with($helpRoute,'employees.')=>'employees',
    str_starts_with($helpRoute,'departments.')=>'departments',
    str_starts_with($helpRoute,'attendance.')=>'attendance',
    $helpRoute==='payroll-setup'=>'payroll-setup',
    $helpRoute==='payroll-preview'=>'payroll-preview',
    str_starts_with($helpRoute,'payroll-settings.')=>'payroll-settings',
    str_starts_with($helpRoute,'payslips.')=>'payslips',
    str_starts_with($helpRoute,'reports.')=>'reports',
    str_starts_with($helpRoute,'payroll-')=>'payroll',
    default=>'dashboard',
  };
  [$helpTitle,$helpIntro,$helpSteps]=$helpGuides[$helpKey];
@endphp
<details class="pro-feature-help" data-feature-help>
  <summary aria-label="Help: {{ $helpTitle }}" title="How do I complete this screen?"><span aria-hidden="true">?</span><span class="visually-hidden">Help</span></summary>
  <aside class="pro-feature-help-panel" aria-label="{{ $helpTitle }} guide">
    <h2 class="h5">{{ $helpTitle }}</h2><p>{{ $helpIntro }}</p>
    <ol>@foreach($helpSteps as $helpStep)<li>{{ $helpStep }}</li> @endforeach</ol>
    <div class="d-flex flex-wrap gap-2">
      @can('settings')<a class="btn btn-sm btn-outline-primary" href="{{ route('payroll-setup') }}">Open payroll setup</a>@endcan
      @can('read')<a class="btn btn-sm btn-outline-primary" href="{{ route('payroll-periods.index') }}">Open payroll</a>@endcan
      <a class="btn btn-sm btn-outline-secondary" href="{{ route('payslips.index') }}">My payslips</a>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-close-feature-help>Close guide</button>
    </div>
  </aside>
</details>
