@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show py-2">
        <i class="bi bi-check-circle-fill"></i> {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show py-2">
        <i class="bi bi-exclamation-triangle-fill"></i> {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
