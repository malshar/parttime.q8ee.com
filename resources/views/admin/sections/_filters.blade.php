<form method="get" action="{{ route($routeName) }}" class="row g-2 align-items-center mb-3">
    <div class="col-auto">
        <select name="term" class="form-select" onchange="this.form.submit()">
            @foreach ($terms as $t)
                <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <input type="text" name="reference" value="{{ $filters['reference'] ?? '' }}" class="form-control" placeholder="{{ __('app.sections.reference') }}">
    </div>
    <div class="col-auto">
        <input type="text" name="course" value="{{ $filters['course'] ?? '' }}" class="form-control" placeholder="{{ __('app.sections.course') }}">
    </div>
    <div class="col-auto">
        <input type="text" name="name" value="{{ $filters['name'] ?? '' }}" class="form-control" placeholder="{{ __('app.sections.filter_name') }}">
    </div>
    <div class="col-auto">
        <input type="text" name="instructor" value="{{ $filters['instructor'] ?? '' }}" class="form-control" placeholder="{{ __('app.sections.filter_instructor') }}">
    </div>
    @if ($showUnassigned ?? false)
        <div class="col-auto form-check">
            <input type="checkbox" name="unassigned" value="1" id="unassigned" class="form-check-input" @checked(request()->boolean('unassigned')) onchange="this.form.submit()">
            <label for="unassigned" class="form-check-label">{{ __('app.sections.unassigned_only') }}</label>
        </div>
    @endif
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-secondary">{{ __('app.sections.filter') }}</button>
    </div>
    <div class="col-auto">
        <a href="{{ route($routeName, ['term' => $term?->id]) }}" class="btn btn-link">{{ __('app.sections.clear') }}</a>
    </div>
</form>
