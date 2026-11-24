@extends('layouts.app')
@section('title', 'Attendance | Payroll Go')
@section('page-title', 'Attendance')
@section('content')
<div class="container-fluid pro-page pro-admin-v5">
  <div class="pro-page-heading"><div><p class="eyebrow">WORKSPACE / ATTENDANCE</p><h1>Daily attendance</h1><p>Record employee status and review attendance history by date.</p></div><span class="pro-page-chip"><i class="ti ti-calendar-event"></i> Manual logging</span></div>
  <div class="pro-module-intro mb-4"><div class="pro-module-symbol"><i class="ti ti-calendar-check"></i></div><div><strong>Daily-rate draft support</strong><span>Recorded present days can be used to suggest DAILY-rate draft base pay during payroll review. Monthly pay, leave pay, and statutory deductions are NOT calculated here.</span></div></div>
  <div class="row g-3 g-xl-4">
    <div class="col-lg-4"><section class="card pro-module-card" aria-labelledby="attendanceCreateTitle"><div class="card-body">
      <div class="pro-section-heading"><div><span class="pro-overline">NEW RECORD</span><h2 id="attendanceCreateTitle">Record attendance</h2><p>Choose an employee, date and status.</p></div><span class="pro-section-icon"><i class="ti ti-calendar-plus"></i></span></div>
      @if($employees->isEmpty())<div class="pro-inline-empty"><i class="ti ti-users-off"></i><p>No active employees available.</p><a href="{{ route('employees.create') }}">Add an employee</a></div>@endif
      <form method="POST" action="{{ route('attendance.store') }}" data-pro-submit-lock>@csrf
        <div class="mb-3"><label class="form-label" for="attendance_employee">Employee *</label><select class="form-select @error('employee_id') is-invalid @enderror" id="attendance_employee" name="employee_id" required @disabled($employees->isEmpty())><option value="">Select an employee</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(old('employee_id')==$employee->id)>{{ $employee->full_name }} ({{ $employee->employee_number }})</option>@endforeach</select>@error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="mb-3"><label class="form-label" for="attendance_date">Work date *</label><input class="form-control @error('work_date') is-invalid @enderror" id="attendance_date" name="work_date" type="date" value="{{ old('work_date', now()->format('Y-m-d')) }}" required>@error('work_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <fieldset class="mb-3"><legend class="form-label fs-3 mb-2">Attendance status *</legend><div class="pro-choice-group">
          @foreach(['present'=>'Present','absent'=>'Absent','leave'=>'On leave'] as $code=>$label)<label class="pro-choice"><input type="radio" name="status" value="{{ $code }}" @checked(old('status','present') === $code) required><span><i class="ti {{ $code === 'present' ? 'ti-circle-check' : ($code === 'absent' ? 'ti-circle-x' : 'ti-calendar-time') }}"></i> {{ $label }}</span></label>@endforeach
        </div></fieldset>
        <details class="mb-3"><summary>Optional time logs and requested overtime</summary><p>No overtime or lateness is inferred from logs. Minutes need explicit approval.</p><label for="time_in" class="form-label">Time in</label><input id="time_in" name="time_in" type="datetime-local" class="form-control mb-2"><label for="time_out" class="form-label">Time out</label><input id="time_out" name="time_out" type="datetime-local" class="form-control mb-2"><label for="overtime_minutes" class="form-label">Requested overtime minutes</label><input id="overtime_minutes" name="overtime_minutes" type="number" min="0" max="960" value="0" class="form-control mb-2"><label for="leave_type" class="form-label">Leave classification</label><input id="leave_type" name="leave_type" class="form-control" maxlength="60"></details>
<div class="mb-3"><label for="attendance_notes" class="form-label">Notes <span class="pro-field-optional">Optional</span></label><textarea class="form-control" id="attendance_notes" name="notes" rows="3" maxlength="500" placeholder="Add relevant context if needed">{{ old('notes') }}</textarea></div>
        <button type="submit" class="btn btn-primary w-100" @disabled($employees->isEmpty())><i class="ti ti-device-floppy me-1"></i> Save attendance</button>
      </form>
    </div></section></div>
    <div class="col-lg-8"><section class="card pro-module-card" aria-labelledby="attendanceHistoryTitle"><div class="card-body">
      <div class="pro-section-heading"><div><span class="pro-overline">ACTIVITY</span><h2 id="attendanceHistoryTitle">Attendance history</h2><p>Review recent manual entries or filter by date.</p></div></div>
      <form method="GET" action="{{ route('attendance.index') }}" class="pro-toolbar"><label class="visually-hidden" for="filter_attendance_date">Filter date</label><input type="date" id="filter_attendance_date" name="date" value="{{ request('date') }}" class="form-control pro-date-filter"><button type="submit" class="btn btn-outline-primary"><i class="ti ti-filter me-1"></i> Filter</button>@if(request('date'))<a href="{{ route('attendance.index') }}" class="btn btn-outline-secondary">Clear</a>@endif<span class="pro-toolbar-meta ms-auto">{{ $records->total() }} records</span></form>
      <div class="table-responsive pro-table-wrap"><table class="table pro-data-table align-middle mb-0"><thead><tr><th>Date</th><th>Employee</th><th>Status</th><th>Notes</th><th class="text-end">Action</th></tr></thead><tbody>
      @forelse($records as $record)
        <tr><td class="pro-cell-primary pro-tabular">{{ $record->work_date->format('M d, Y') }}</td><td><strong class="pro-cell-primary">{{ $record->employee->full_name }}</strong><small class="d-block text-muted">{{ $record->employee->employee_number }}</small></td><td><span class="badge status-{{ $record->status }}">{{ ucfirst($record->status) }}</span></td><td class="pro-note-cell">{{ $record->notes ?: '—' }}</td><td class="text-end"><form method="POST" action="{{ route('attendance.destroy', $record) }}" onsubmit="return confirm('Remove this attendance record?');">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash me-1"></i> Remove</button></form></td></tr>
      @empty<tr><td colspan="5"><div class="pro-empty-state"><span><i class="ti ti-calendar-off"></i></span><h3>No attendance records</h3><p>{{ request('date') ? 'No results for that date. Try a different day.' : 'Saved daily attendance will appear here.' }}</p></div></td></tr>@endforelse
      </tbody></table></div>
      @if($records->hasPages())<div class="pro-pagination">{{ $records->links() }}</div>@endif
    </div></section></div>
  </div>
</div>
@endsection
