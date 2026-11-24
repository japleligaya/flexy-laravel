<nav aria-label="Payroll workflow" class="payroll-journey mb-4">
  <ol>
  @foreach(['Set Up','Generate Payroll','Review','Approve / Finalize'] as $label)
    <li @if($loop->iteration === $workflowStep) aria-current="step" @endif class="{{ $loop->iteration < $workflowStep ? 'is-done' : '' }}">
      <span>{{ $loop->iteration }}</span><strong>{{ $label }}</strong>
    </li>
  @endforeach
  </ol>
</nav>
