{{--
    Shared profile fields for the instructor profile page and the admin profile edit page.
    Expects $instructor; $sensitiveAttrs (string, optional) is appended to the sensitive inputs
    (the admin page passes autocomplete="off"). Grid: compact on desktop (two or three rows per
    card), short fields paired on phones (col-6) so the form stays short on a mobile screen.
--}}
@php($sensitiveAttrs = $sensitiveAttrs ?? '')
@php($reenter = old() ? '<div class="form-text">'.e(__('app.profile.sensitive_reenter')).'</div>' : '')
@php($employerChoice = old('employer_choice', \App\Support\KuwaitLists::isEmployer((string) $instructor->employer) ? $instructor->employer : ($instructor->employer ? ($instructor->employer_sector === 'private' ? 'private' : 'other') : '')))
@php($bankChoice = old('bank_choice', \App\Support\KuwaitLists::bankKey((string) $instructor->bank_name) ?? ($instructor->bank_name ? 'other' : '')))

<div class="card mb-3">
    <div class="card-header">{{ __('app.profile.personal') }}</div>
    <div class="card-body">
        <div class="row g-2 g-md-3">
            <div class="col-12 col-md-6"><label class="form-label mb-1">{{ __('app.profile.full_name') }}</label>
                <input name="full_name" value="{{ old('full_name', $instructor->full_name) }}" class="form-control" required></div>
            <div class="col-6 col-md-3"><label class="form-label mb-1">{{ __('app.profile.civil_id') }}</label>
                <input name="civil_id" value="{{ old('civil_id', old() ? '' : $instructor->civil_id) }}" class="form-control" dir="ltr" inputmode="numeric" maxlength="12" {!! $sensitiveAttrs !!} required>
                {!! $reenter !!}</div>
            <div class="col-6 col-md-3"><label class="form-label mb-1">{{ __('app.profile.civil_id_expires_on') }}</label>
                <input type="date" name="civil_id_expires_on" value="{{ old('civil_id_expires_on', optional($instructor->civil_id_expires_on)->format('Y-m-d')) }}" class="form-control" required></div>

            <div class="col-12 col-md-3"><label class="form-label mb-1" for="nationality">{{ __('app.profile.nationality') }}</label>
                @php($storedNationality = (string) $instructor->nationality)
                @php($legacyNationality = $storedNationality !== '' && strlen($storedNationality) !== 2)
                @php($nationalityChoice = (string) old('nationality', $legacyNationality ? \App\Http\Requests\ProfileRequest::KEEP : $storedNationality))
                <select id="nationality" name="nationality" class="form-select" required>
                    <option value="" @selected($nationalityChoice === '')>{{ __('app.common.choose') }}</option>
                    @if ($legacyNationality)
                        <option value="{{ \App\Http\Requests\ProfileRequest::KEEP }}" @selected($nationalityChoice === \App\Http\Requests\ProfileRequest::KEEP)>{{ $storedNationality }}</option>
                    @endif
                    @foreach (__('app.countries') as $code => $name)
                        <option value="{{ $code }}" @selected($nationalityChoice === $code)>{{ $name }}</option>
                    @endforeach
                </select>
                @if ($legacyNationality)
                    <div class="form-text">{{ __('app.profile.nationality_legacy', ['value' => $instructor->nationality]) }}</div>
                @endif</div>
            <div class="col-12 col-md-3"><label class="form-label mb-1">{{ __('app.profile.mobile') }}</label>
                <input name="mobile" value="{{ old('mobile', $instructor->mobile) }}" class="form-control" dir="ltr" inputmode="tel" required></div>
            <div class="col-6 col-md-3"><label class="form-label mb-1">{{ __('app.profile.work_phone') }}</label>
                <input name="work_phone" value="{{ old('work_phone', $instructor->work_phone) }}" class="form-control" dir="ltr" inputmode="tel"></div>
            <div class="col-6 col-md-3"><label class="form-label mb-1">{{ __('app.profile.home_phone') }}</label>
                <input name="home_phone" value="{{ old('home_phone', $instructor->home_phone) }}" class="form-control" dir="ltr" inputmode="tel"></div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">{{ __('app.profile.work') }}</div>
    <div class="card-body">
        {{-- Each row stands alone so the conditional fields (employer name, sector, experience) never shift the others. --}}
        <div class="row g-2 g-md-3 mb-2 mb-md-3">
            <div class="col-12 col-md-6"><label class="form-label mb-1" for="employer_choice">{{ __('app.profile.employer') }}</label>
                <select id="employer_choice" name="employer_choice" class="form-select" required data-toggle-other="employer_other_wrap" data-toggle-sector="employer_sector_wrap" data-toggle-row="employer_extra_row">
                    <option value="">{{ __('app.common.choose') }}</option>
                    @foreach (\App\Support\KuwaitLists::EMPLOYERS as $name)
                        <option value="{{ $name }}" @selected($employerChoice === $name)>{{ $name }}</option>
                    @endforeach
                    <option value="private" @selected($employerChoice === 'private')>{{ __('app.profile.employer_private') }}</option>
                    <option value="other" @selected($employerChoice === 'other')>{{ __('app.profile.employer_other') }}</option>
                </select></div>
            <div class="col-12 col-md-6"><label class="form-label mb-1">{{ __('app.profile.job_title') }}</label>
                <input name="job_title" value="{{ old('job_title', $instructor->job_title) }}" class="form-control" required></div>
        </div>
        <div class="row g-2 g-md-3 mb-2 mb-md-3" id="employer_extra_row">
            <div class="col-12 col-md-6" id="employer_other_wrap"><label class="form-label mb-1">{{ __('app.profile.employer_name') }}</label>
                <input name="employer_other" value="{{ old('employer_other', in_array($employerChoice, ['private', 'other'], true) ? $instructor->employer : '') }}" class="form-control"></div>
            <div class="col-6 col-md-3" id="employer_sector_wrap"><label class="form-label mb-1">{{ __('app.profile.employer_sector') }}</label>
                <select name="employer_sector" class="form-select">
                    @foreach (\App\Models\Instructor::SECTORS as $sector)
                        <option value="{{ $sector }}" @selected(old('employer_sector', $instructor->employer_sector) === $sector)>{{ __('app.profile.sectors.'.$sector) }}</option>
                    @endforeach
                </select></div>
        </div>
        <div class="row g-2 g-md-3 mb-2 mb-md-3">
            <div class="col-12 col-md-6"><label class="form-label mb-1">{{ __('app.profile.degree_title') }}</label>
                <input name="degree_title" value="{{ old('degree_title', $instructor->degree_title) }}" class="form-control" required></div>
            <div class="col-6 col-md-3"><label class="form-label mb-1">{{ __('app.profile.degree_country') }}</label>
                <select name="degree_country" class="form-select" required>
                    @foreach (__('app.countries') as $code => $name)
                        <option value="{{ $code }}" @selected(old('degree_country', $instructor->degree_country ?: 'KW') === $code)>{{ $name }}</option>
                    @endforeach
                </select></div>
            <div class="col-6 col-md-3"><label class="form-label mb-1">{{ __('app.profile.degree_obtained_on') }}</label>
                <input type="date" name="degree_obtained_on" value="{{ old('degree_obtained_on', optional($instructor->degree_obtained_on)->format('Y-m-d')) }}" class="form-control" required></div>
        </div>
        <div class="row g-2 g-md-3">
            <div class="col-6 col-md-3"><label class="form-label mb-1">{{ __('app.profile.highest_degree') }}</label>
                <select name="highest_degree" id="highest_degree" class="form-select" required>
                    @foreach (\App\Models\Instructor::DEGREES as $degree)
                        <option value="{{ $degree }}" @selected(old('highest_degree', $instructor->highest_degree) === $degree)>{{ __('app.profile.degrees.'.$degree) }}</option>
                    @endforeach
                </select></div>
            <div class="col-6 col-md-3" id="experience-wrap"><label class="form-label mb-1">{{ __('app.profile.experience_years') }}</label>
                <input type="number" name="experience_years" min="0" max="60" value="{{ old('experience_years', $instructor->experience_years) }}" class="form-control" dir="ltr" inputmode="numeric">
                <div class="form-text">{{ __('app.profile.experience_hint') }}</div></div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">{{ __('app.profile.bank') }}</div>
    <div class="card-body">
        <div class="row g-2 g-md-3">
            <div class="col-12 col-md-4"><label class="form-label mb-1" for="bank_choice">{{ __('app.profile.bank_name') }}</label>
                <select id="bank_choice" name="bank_choice" class="form-select" required data-toggle-other="bank_other_wrap">
                    <option value="">{{ __('app.common.choose') }}</option>
                    @foreach (\App\Support\KuwaitLists::BANKS as $key => $name)
                        <option value="{{ $key }}" @selected($bankChoice === (string) $key)>{{ $name }}</option>
                    @endforeach
                    <option value="other" @selected($bankChoice === 'other')>{{ __('app.profile.bank_other') }}</option>
                </select></div>
            <div class="col-12 col-md-4" id="bank_other_wrap"><label class="form-label mb-1">{{ __('app.profile.bank_other_name') }}</label>
                <input name="bank_other" value="{{ old('bank_other', $bankChoice === 'other' ? $instructor->bank_name : '') }}" class="form-control"></div>
            <div class="col-12 col-md-4"><label class="form-label mb-1">{{ __('app.profile.bank_branch') }}</label>
                <input name="bank_branch" value="{{ old('bank_branch', $instructor->bank_branch) }}" class="form-control"></div>

            <div class="col-12 col-md-6"><label class="form-label mb-1">{{ __('app.profile.iban') }}</label>
                <input name="iban" value="{{ old('iban', old() ? '' : $instructor->iban) }}" class="form-control" dir="ltr" autocapitalize="characters" {!! $sensitiveAttrs !!}>
                {!! $reenter !!}</div>
            <div class="col-6 col-md-3"><label class="form-label mb-1">{{ __('app.profile.basic_salary') }}</label>
                <input type="number" step="0.001" name="basic_salary" value="{{ old('basic_salary', old() ? '' : $instructor->basic_salary) }}" class="form-control" dir="ltr" inputmode="decimal" {!! $sensitiveAttrs !!} required>
                {!! $reenter !!}</div>
            <div class="col-6 col-md-3"><label class="form-label mb-1">{{ __('app.profile.total_salary') }}</label>
                <input type="number" step="0.001" name="total_salary" value="{{ old('total_salary', old() ? '' : $instructor->total_salary) }}" class="form-control" dir="ltr" inputmode="decimal" {!! $sensitiveAttrs !!} required>
                {!! $reenter !!}</div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    // Employer / bank "other" fields and the sector field follow the chosen list entry.
    function toggle(select) {
        var other = document.getElementById(select.dataset.toggleOther);
        if (other) other.classList.toggle('d-none', !['private', 'other'].includes(select.value));
        var sector = select.dataset.toggleSector ? document.getElementById(select.dataset.toggleSector) : null;
        if (sector) sector.classList.toggle('d-none', select.value !== 'other');
        var row = select.dataset.toggleRow ? document.getElementById(select.dataset.toggleRow) : null;
        if (row) row.classList.toggle('d-none', !['private', 'other'].includes(select.value));
    }
    document.querySelectorAll('select[data-toggle-other]').forEach(function (s) { toggle(s); s.addEventListener('change', function () { toggle(s); }); });

    // The IBAN's bank code (positions 5-8) pre-selects the bank.
    var iban = document.querySelector('input[name="iban"]'), bank = document.getElementById('bank_choice');
    var codes = @json(array_keys(\App\Support\KuwaitLists::BANKS));
    if (iban && bank) iban.addEventListener('input', function () {
        var code = iban.value.replace(/\s+/g, '').substring(4, 8).toUpperCase();
        if (codes.includes(code)) { bank.value = code; toggle(bank); }
    });

    // Experience years only matter for bachelor holders.
    var degree = document.getElementById('highest_degree'), experience = document.getElementById('experience-wrap');
    function toggleExperience() { experience.classList.toggle('d-none', degree.value !== 'bachelor'); }
    degree.addEventListener('change', toggleExperience);
    toggleExperience();
})();
</script>
@endpush
