{{-- Expects $instructor. Shown in the stage-2 section after approval; posts back to the page that includes it. --}}
<div class="card mb-3 border-warning">
    <div class="card-header">{{ __('app.profile.salary_title') }}</div>
    <div class="card-body">
        <form method="post" action="{{ route('instructor.salary.update') }}" class="row g-2 g-md-3 align-items-end">
            @csrf
            @method('put')
            <div class="col-6 col-md-4"><label class="form-label mb-1">{{ __('app.profile.basic_salary') }}</label>
                <input type="number" step="0.001" name="basic_salary" value="{{ old('basic_salary') }}" class="form-control" dir="ltr" inputmode="decimal" autocomplete="off" required></div>
            <div class="col-6 col-md-4"><label class="form-label mb-1">{{ __('app.profile.total_salary') }}</label>
                <input type="number" step="0.001" name="total_salary" value="{{ old('total_salary') }}" class="form-control" dir="ltr" inputmode="decimal" autocomplete="off" required></div>
            <div class="col-12 col-md-4"><button class="btn btn-eet">{{ __('app.common.save') }}</button></div>
        </form>
        <div class="form-text">{{ __('app.profile.sensitive_reenter') }}</div>
    </div>
</div>
