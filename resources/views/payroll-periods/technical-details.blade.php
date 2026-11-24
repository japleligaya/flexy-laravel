<details class="mt-3 payroll-technical" id="technical-{{ $payEntry->id??'preview-'.$payEntry->employee_id }}">
  <summary>Technical details (for payroll support)</summary>
  <p>These are the original calculation messages and source references. They do not change payroll amounts.</p>
  <ul>@foreach(array_merge($presentation['problems'],$presentation['calc']['warnings']??[]) as $raw)<li>{{ $raw }}</li> @endforeach</ul>
  <details><summary>Month-to-date / year-to-date records</summary><pre>{{ json_encode($presentation['calc']['history']??[],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
  <details><summary>Calculation and government-rule references</summary><pre>{{ json_encode($presentation['calc'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
</details>
