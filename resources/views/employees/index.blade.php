@extends('layouts.app')
@section('title', 'Employees | PayrollGo')
@section('page-title', 'Employees')
@section('content')
<div class="container-fluid pro-page pro-admin-v5">
  <div class="pro-page-heading">
    <div><p class="eyebrow">WORKSPACE / PEOPLE</p><h1>Employee directory</h1><p>One place to manage your workforce, departments and compensation references.</p></div>
    <div class="pro-heading-actions"><a class="btn btn-primary" href="{{ route('employees.create') }}"><i class="ti ti-user-plus me-1"></i> Add employee</a></div>
  </div>
  <div class="pro-module-intro mb-4">
    <div class="pro-module-symbol"><i class="ti ti-users-group"></i></div>
    <div><strong>People workspace</strong><span>Maintain employee profiles and employment status in a secure, searchable directory.</span></div>
    <div class="pro-intro-count"><strong>{{ number_format($employees->total()) }}</strong><span>{{ request('q') ? 'Search results' : 'Employees' }}</span></div>
  </div>
  <section class="card pro-module-card" aria-labelledby="employeesListTitle">
    <div class="card-body">
      <div class="pro-section-heading"><div><span class="pro-overline">DIRECTORY</span><h2 id="employeesListTitle">Employee records</h2><p>Search by name, employee number or email.</p></div></div>
      <form class="pro-toolbar" method="GET" action="{{ route('employees.index') }}" role="search">
        <div class="pro-search-field"><i class="ti ti-search" aria-hidden="true"></i><input type="search" class="form-control" name="q" value="{{ request('q') }}" placeholder="Search employee, ID or email" aria-label="Search employee, ID or email"></div>
        <button type="submit" class="btn btn-primary"><i class="ti ti-search me-1"></i> Search</button>
        @if(request()->filled('q'))<a href="{{ route('employees.index') }}" class="btn btn-outline-secondary">Clear</a>@endif
        <span class="pro-toolbar-meta ms-auto">{{ $employees->total() }} {{ $employees->total() === 1 ? 'record' : 'records' }}</span>
      </form>
      <div class="table-responsive pro-table-wrap">
        <table class="table pro-data-table align-middle mb-0">
          <thead><tr><th scope="col">Employee</th><th scope="col">Department / Position</th><th scope="col" class="text-end">Authoritative pay</th><th scope="col">Status</th><th scope="col" class="text-end">Actions</th></tr></thead>
          <tbody>
          @forelse($employees as $employee)
            <tr>
              <td><div class="pro-person-cell"><span class="pro-person-avatar">{{ strtoupper(mb_substr($employee->first_name, 0, 1).mb_substr($employee->last_name, 0, 1)) }}</span><div><strong>{{ $employee->full_name }}</strong><small class="d-block text-muted">{{ $employee->employee_number }} · {{ $employee->email ?: 'No email provided' }}</small></div></div></td>
              <td><strong class="pro-cell-primary">{{ $employee->department?->name ?? 'Unassigned' }}</strong><small class="d-block text-muted">{{ $employee->position }}</small></td>
              <td class="text-end pro-tabular">@if($rate=$employee->currentRate()){{ \App\Support\Money::display((int)$rate->amount_cents) }}<small class="d-block">{{ $rate->basis }} - {{ $rate->effective_on->toDateString() }}</small> @else <span class="text-warning">Needs rate review</span> @endif @if($employee->salaryWarning())<small class="d-block text-warning">{{ $employee->salaryWarning() }}</small> @endif </td>
              <td><span class="badge status-{{ $employee->is_active ? 'active' : 'inactive' }}">{{ $employee->is_active ? 'Active' : 'Inactive' }}</span></td>
              <td class="text-end"><div class="pro-row-actions justify-content-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('employees.edit', $employee) }}"><i class="ti ti-pencil me-1"></i> Edit</a><form method="POST" action="{{ route('employees.destroy', $employee) }}" onsubmit="return confirm('Delete this employee? Employees with payroll/attendance records cannot be deleted.');">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit"><i class="ti ti-trash me-1"></i> Delete</button></form></div></td>
            </tr>
          @empty
            <tr><td colspan="5"><div class="pro-empty-state"><span><i class="ti ti-users-off"></i></span><h3>No employees found</h3><p>{{ request('q') ? 'Try another search term or clear your search.' : 'Create your first employee to begin building your workforce.' }}</p><a class="btn btn-outline-primary btn-sm" href="{{ request('q') ? route('employees.index') : route('employees.create') }}">{{ request('q') ? 'Clear search' : 'Add an employee' }}</a></div></td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
      @if($employees->hasPages())<div class="pro-pagination">{{ $employees->links() }}</div>@endif
    </div>
  </section>
</div>
@endsection
