@if(session('success') || session('error') || $errors->any())
    {{-- Important: this wrapper must NOT use .container-fluid.
         Flexy gives direct .container-fluid children of .body-wrapper a large min-height,
         which caused an empty 700px block above the dashboard. --}}
    <div class="pro-alerts" aria-live="polite">
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="status">
                <i class="ti ti-circle-check fs-5" aria-hidden="true"></i>
                <span class="flex-grow-1">{{ session('success') }}</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
            </div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
                <i class="ti ti-alert-circle fs-5" aria-hidden="true"></i><span>{{ session('error') }}</span>
            </div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger" role="alert">
                <strong>Check these fields:</strong>
                <ul class="mb-0 mt-2 ps-3">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif
