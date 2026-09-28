<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"></head>
<body style="font-family: Tahoma, 'Segoe UI', sans-serif; background:#f6f8fb; margin:0; padding:24px;">
<div style="max-width:600px; margin:0 auto; background:#fff; border:1px solid #e3e8ef; border-radius:8px; padding:24px; direction:rtl; text-align:right;">
    <h2 style="color:#1d4e89; margin-top:0;">{{ __('app.site_name', [], 'ar') }}</h2>
    @yield('body')
    <p style="color:#6c757d; font-size:.9em;">{{ __('app.mail.automated', [], 'ar') }}</p>
    <hr style="border:none; border-top:1px solid #e3e8ef; margin:20px 0;">
    <div style="color:#6c757d; font-size:.85em;">
        <div>{{ __('app.dept_name', [], 'ar') }} — {{ __('app.college_name', [], 'ar') }}</div>
        <div style="direction:ltr; text-align:left; margin-top:4px;">{{ __('app.dept_name', [], 'en') }} — {{ __('app.college_name', [], 'en') }}</div>
    </div>
</div>
</body>
</html>
