<div class="cf-turnstile my-3" data-sitekey="{{ config('services.turnstile.site_key') }}" data-language="{{ app()->getLocale() }}"></div>
@once
    @push('scripts')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endpush
@endonce
