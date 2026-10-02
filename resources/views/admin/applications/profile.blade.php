@extends('admin.layout')
@section('title', __('app.review.edit_profile'))
@section('content')
<div class="row justify-content-center"><div class="col-md-10">
    <h1 class="h4 mb-3">{{ __('app.review.edit_profile') }} — {{ $instructor->full_name }}</h1>
    <div class="alert alert-warning">{{ __('app.review.profile_edit_warning') }}</div>
    <form method="post" action="{{ route('admin.applications.profile.update', $application) }}">
        @csrf
        @method('put')

        <div class="card mb-3">
            <div class="card-header">{{ __('app.profile.personal') }}</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">{{ __('app.profile.full_name') }}</label>
                        <input name="full_name" value="{{ old('full_name', $instructor->full_name) }}" class="form-control" required></div>
                    <div class="col-md-6 mb-3"><label class="form-label">{{ __('app.profile.civil_id') }}</label>
                        <input name="civil_id" value="{{ old('civil_id', old() ? '' : $instructor->civil_id) }}" class="form-control" dir="ltr" maxlength="12" autocomplete="off" required>
                        @if (old())
                            <div class="form-text">{{ __('app.profile.sensitive_reenter') }}</div>
                        @endif</div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">{{ __('app.profile.civil_id_expires_on') }}</label>
                        <input type="date" name="civil_id_expires_on" value="{{ old('civil_id_expires_on', optional($instructor->civil_id_expires_on)->format('Y-m-d')) }}" class="form-control" required></div>
                    <div class="col-md-6 mb-3"><label class="form-label" for="nationality">{{ __('app.profile.nationality') }}</label>
                        <select id="nationality" name="nationality" class="form-select" required>
                            @foreach (__('app.countries') as $code => $name)
                                <option value="{{ $code }}" @selected(old('nationality', strlen($instructor->nationality) === 2 ? $instructor->nationality : 'ZZ') === $code)>{{ $name }}</option>
                            @endforeach
                        </select>
                        @if ($instructor->nationality && strlen($instructor->nationality) !== 2)
                            <div class="form-text">{{ __('app.profile.nationality_legacy', ['value' => $instructor->nationality]) }}</div>
                        @endif</div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3"><label class="form-label">{{ __('app.profile.mobile') }}</label>
                        <input name="mobile" value="{{ old('mobile', $instructor->mobile) }}" class="form-control" dir="ltr" required></div>
                    <div class="col-md-4 mb-3"><label class="form-label">{{ __('app.profile.work_phone') }}</label>
                        <input name="work_phone" value="{{ old('work_phone', $instructor->work_phone) }}" class="form-control" dir="ltr"></div>
                    <div class="col-md-4 mb-3"><label class="form-label">{{ __('app.profile.home_phone') }}</label>
                        <input name="home_phone" value="{{ old('home_phone', $instructor->home_phone) }}" class="form-control" dir="ltr"></div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">{{ __('app.profile.work') }}</div>
            <div class="card-body">
                @include('instructor._employer_bank_fields', ['instructor' => $instructor])
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">{{ __('app.profile.job_title') }}</label>
                        <input name="job_title" value="{{ old('job_title', $instructor->job_title) }}" class="form-control" required></div>
                    <div class="col-md-6 mb-3"><label class="form-label">{{ __('app.profile.highest_degree') }}</label>
                        <select name="highest_degree" id="highest_degree" class="form-select" required>
                            @foreach (\App\Models\Instructor::DEGREES as $degree)
                                <option value="{{ $degree }}" @selected(old('highest_degree', $instructor->highest_degree) === $degree)>{{ __('app.profile.degrees.'.$degree) }}</option>
                            @endforeach
                        </select></div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">{{ __('app.profile.degree_title') }}</label>
                        <input name="degree_title" value="{{ old('degree_title', $instructor->degree_title) }}" class="form-control" required></div>
                    <div class="col-md-3 mb-3"><label class="form-label">{{ __('app.profile.degree_country') }}</label>
                        @php($countries = ['KW', 'SA', 'AE', 'BH', 'QA', 'OM', 'EG', 'JO', 'GB', 'US', 'CA', 'AU', 'MY', 'IN', 'PK', 'TR', 'DE', 'FR', 'ZZ'])
                        <select name="degree_country" class="form-select" required>
                            @foreach ($countries as $code)
                                <option value="{{ $code }}" @selected(old('degree_country', $instructor->degree_country ?: 'KW') === $code)>{{ __('app.countries.'.$code) }}</option>
                            @endforeach
                        </select></div>
                    <div class="col-md-3 mb-3"><label class="form-label">{{ __('app.profile.degree_obtained_on') }}</label>
                        <input type="date" name="degree_obtained_on" value="{{ old('degree_obtained_on', optional($instructor->degree_obtained_on)->format('Y-m-d')) }}" class="form-control" required></div>
                </div>
                <div class="row" id="experience-wrap">
                    <div class="col-md-6 mb-3"><label class="form-label">{{ __('app.profile.experience_years') }}</label>
                        <input type="number" name="experience_years" min="0" max="60" value="{{ old('experience_years', $instructor->experience_years) }}" class="form-control">
                        <div class="form-text">{{ __('app.profile.experience_hint') }}</div></div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">{{ __('app.profile.bank') }}</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">{{ __('app.profile.bank_branch') }}</label>
                        <input name="bank_branch" value="{{ old('bank_branch', $instructor->bank_branch) }}" class="form-control"></div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">{{ __('app.profile.iban') }}</label>
                        <input name="iban" value="{{ old('iban', old() ? '' : $instructor->iban) }}" class="form-control" dir="ltr" autocomplete="off">
                        @if (old())
                            <div class="form-text">{{ __('app.profile.sensitive_reenter') }}</div>
                        @endif</div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label">{{ __('app.profile.basic_salary') }}</label>
                        <input type="number" step="0.001" name="basic_salary" value="{{ old('basic_salary', old() ? '' : $instructor->basic_salary) }}" class="form-control" dir="ltr" autocomplete="off" required>
                        @if (old())
                            <div class="form-text">{{ __('app.profile.sensitive_reenter') }}</div>
                        @endif</div>
                    <div class="col-md-6 mb-3"><label class="form-label">{{ __('app.profile.total_salary') }}</label>
                        <input type="number" step="0.001" name="total_salary" value="{{ old('total_salary', old() ? '' : $instructor->total_salary) }}" class="form-control" dir="ltr" autocomplete="off" required>
                        @if (old())
                            <div class="form-text">{{ __('app.profile.sensitive_reenter') }}</div>
                        @endif</div>
                </div>
            </div>
        </div>

        <button class="btn btn-eet">{{ __('app.common.save') }}</button>
        <a class="btn btn-link" href="{{ route('admin.applications.show', $application) }}">{{ __('app.common.cancel') }}</a>
    </form>
</div></div>
@endsection

@push('scripts')
<script>
    const degreeSelect = document.getElementById('highest_degree');
    const experienceWrap = document.getElementById('experience-wrap');
    function toggleExperience() {
        experienceWrap.style.display = degreeSelect.value === 'bachelor' ? '' : 'none';
    }
    degreeSelect.addEventListener('change', toggleExperience);
    toggleExperience();
</script>
@endpush
