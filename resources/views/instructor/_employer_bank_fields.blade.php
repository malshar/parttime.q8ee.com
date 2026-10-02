@php
    $employerChoice = old('employer_choice', \App\Support\KuwaitLists::isEmployer((string) $instructor->employer) ? $instructor->employer : ($instructor->employer ? ($instructor->employer_sector === 'private' ? 'private' : 'other') : ''));
    $bankChoice = old('bank_choice', \App\Support\KuwaitLists::bankKey((string) $instructor->bank_name) ?? ($instructor->bank_name ? 'other' : ''));
@endphp
@if ($section === 'employer')
    <div class="row">
        <div class="col-md-6 mb-3"><label class="form-label" for="employer_choice">{{ __('app.profile.employer') }}</label>
            <select id="employer_choice" name="employer_choice" class="form-select" required data-toggle-other="employer_other_wrap" data-toggle-sector="employer_sector_wrap">
                <option value="">{{ __('app.common.choose') }}</option>
                @foreach (\App\Support\KuwaitLists::EMPLOYERS as $name)
                    <option value="{{ $name }}" @selected($employerChoice === $name)>{{ $name }}</option>
                @endforeach
                <option value="private" @selected($employerChoice === 'private')>{{ __('app.profile.employer_private') }}</option>
                <option value="other" @selected($employerChoice === 'other')>{{ __('app.profile.employer_other') }}</option>
            </select></div>
        <div class="col-md-6 mb-3" id="employer_other_wrap"><label class="form-label">{{ __('app.profile.employer_name') }}</label>
            <input name="employer_other" value="{{ old('employer_other', in_array($employerChoice, ['private', 'other'], true) ? $instructor->employer : '') }}" class="form-control"></div>
        <div class="col-md-6 mb-3" id="employer_sector_wrap"><label class="form-label">{{ __('app.profile.employer_sector') }}</label>
            <select name="employer_sector" class="form-select">
                @foreach (\App\Models\Instructor::SECTORS as $sector)
                    <option value="{{ $sector }}" @selected(old('employer_sector', $instructor->employer_sector) === $sector)>{{ __('app.profile.sectors.'.$sector) }}</option>
                @endforeach
            </select></div>
    </div>
@elseif ($section === 'bank')
    <div class="row">
        <div class="col-md-6 mb-3"><label class="form-label" for="bank_choice">{{ __('app.profile.bank_name') }}</label>
            <select id="bank_choice" name="bank_choice" class="form-select" required data-toggle-other="bank_other_wrap">
                <option value="">{{ __('app.common.choose') }}</option>
                @foreach (\App\Support\KuwaitLists::BANKS as $key => $name)
                    <option value="{{ $key }}" @selected($bankChoice === (string) $key)>{{ $name }}</option>
                @endforeach
                <option value="other" @selected($bankChoice === 'other')>{{ __('app.profile.bank_other') }}</option>
            </select></div>
        <div class="col-md-6 mb-3" id="bank_other_wrap"><label class="form-label">{{ __('app.profile.bank_other_name') }}</label>
            <input name="bank_other" value="{{ old('bank_other', $bankChoice === 'other' ? $instructor->bank_name : '') }}" class="form-control"></div>
    </div>
    <script>
    (function () {
        function toggle(select) {
            var other = document.getElementById(select.dataset.toggleOther);
            if (other) other.classList.toggle('d-none', !['private', 'other'].includes(select.value));
            var sector = select.dataset.toggleSector ? document.getElementById(select.dataset.toggleSector) : null;
            if (sector) sector.classList.toggle('d-none', select.value !== 'other');
        }
        document.querySelectorAll('select[data-toggle-other]').forEach(function (s) { toggle(s); s.addEventListener('change', function () { toggle(s); }); });
        var iban = document.querySelector('input[name="iban"]'), bank = document.getElementById('bank_choice');
        var codes = @json(array_keys(\App\Support\KuwaitLists::BANKS));
        if (iban && bank) iban.addEventListener('input', function () {
            var code = iban.value.replace(/\s+/g, '').substring(4, 8).toUpperCase();
            if (codes.includes(code)) bank.value = code;
        });
    })();
    </script>
@endif
