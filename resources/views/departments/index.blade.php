@extends('layouts.app')
@section('title', 'Departments | PayrollGo')
@section('page-title', 'Departments')
@section('content')
<div class="container-fluid pro-page pro-admin-v5">
  <div class="pro-page-heading"><div><p class="eyebrow">WORKSPACE / TEAMS</p><h1>Departments</h1><p>Organize the people behind your operations and keep team records updated.</p></div></div>
  <div class="pro-module-intro mb-4"><div class="pro-module-symbol"><i class="ti ti-sitemap"></i></div><div><strong>Organized teams, easier management</strong><span>Employees can be assigned to a department from their employee profile.</span></div><div class="pro-intro-count"><strong>{{ $departments->count() }}</strong><span>Departments</span></div></div>
  <div class="row g-3 g-xl-4">
    <div class="col-lg-4"><section class="card pro-module-card" aria-labelledby="newDeptTitle"><div class="card-body">
      <div class="pro-section-heading"><div><span class="pro-overline">CREATE</span><h2 id="newDeptTitle">New department</h2><p>Add another team to your organization.</p></div><span class="pro-section-icon"><i class="ti ti-plus"></i></span></div>
      <form method="POST" action="{{ route('departments.store') }}" data-pro-submit-lock>@csrf
        <div class="mb-3"><label class="form-label" for="department_name">Name *</label><input id="department_name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" placeholder="e.g. Human Resources" maxlength="255" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="mb-3"><label class="form-label" for="department_description">Description <span class="pro-field-optional">Optional</span></label><textarea id="department_description" name="description" class="form-control @error('description') is-invalid @enderror" rows="4" maxlength="1500" placeholder="What does this team handle?">{{ old('description') }}</textarea>@error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <button type="submit" class="btn btn-primary w-100"><i class="ti ti-plus me-1"></i> Create department</button>
      </form>
    </div></section></div>
    <div class="col-lg-8"><section class="card pro-module-card" aria-labelledby="departmentListTitle"><div class="card-body">
      <div class="pro-section-heading"><div><span class="pro-overline">MANAGE</span><h2 id="departmentListTitle">All departments</h2><p>Edit names and descriptions in place. Teams with employees cannot be deleted.</p></div><span class="pro-count-chip">{{ $departments->count() }} total</span></div>
      <div class="pro-dept-list">
        @forelse($departments as $department)
          <article class="pro-dept-item"><div class="pro-dept-icon"><i class="ti ti-building-community"></i></div><div class="pro-dept-main">
            <form id="department-{{ $department->id }}" method="POST" action="{{ route('departments.update', $department) }}" data-pro-submit-lock>@csrf @method('PUT')
              <label class="visually-hidden" for="department-name-{{ $department->id }}">Department name</label><input id="department-name-{{ $department->id }}" class="form-control form-control-sm pro-dept-name" name="name" value="{{ $department->name }}" required maxlength="255">
              <label class="visually-hidden" for="department-desc-{{ $department->id }}">Department description</label><input id="department-desc-{{ $department->id }}" class="form-control form-control-sm" name="description" value="{{ $department->description }}" maxlength="1500" placeholder="Add a description (optional)">
            </form>
            <span class="pro-dept-population"><i class="ti ti-users"></i> {{ $department->employees_count }} {{ $department->employees_count === 1 ? 'employee' : 'employees' }}</span>
          </div><div class="pro-row-actions pro-dept-actions"><button class="btn btn-sm btn-outline-primary" type="submit" form="department-{{ $department->id }}"><i class="ti ti-device-floppy me-1"></i> Save</button><form method="POST" action="{{ route('departments.destroy', $department) }}" onsubmit="return confirm('Delete this empty department?');">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger" @if($department->employees_count > 0) disabled title="Move employees first" @endif><i class="ti ti-trash me-1"></i> Delete</button></form></div></article>
        @empty
          <div class="pro-empty-state"><span><i class="ti ti-sitemap"></i></span><h3>No departments yet</h3><p>Use the form to create your first department.</p></div>
        @endforelse
      </div>
    </div></section></div>
  </div>
</div>
@endsection
