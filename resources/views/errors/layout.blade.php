@php
    try {
        $company = \App\Models\CompanySetting::current();
        $name = $company->name;
        $logo = $company->logoUrl();
    } catch (\Throwable) {
        $name = config('app.name');
        $logo = null;
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ $name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px;
               font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #fff7ed; color: #111827; }
        .card { width: 100%; max-width: 420px; background: #fff; border-radius: 24px; padding: 32px 28px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,.06); }
        .logo { width: 64px; height: 64px; border-radius: 16px; object-fit: contain; margin: 0 auto 12px; display: block; }
        .mark { width: 64px; height: 64px; border-radius: 16px; margin: 0 auto 12px; display: flex; align-items: center; justify-content: center; background: #ea580c; color: #fff; font-weight: 800; font-size: 24px; }
        .code { font-size: 13px; font-weight: 700; letter-spacing: .08em; color: #9a3412; text-transform: uppercase; }
        h1 { font-size: 22px; margin: 6px 0 8px; }
        p { color: #4b5563; line-height: 1.5; margin: 0 0 20px; }
        a.button { display: inline-block; background: #111827; color: #fff; text-decoration: none; font-weight: 700; padding: 12px 22px; border-radius: 14px; }
        .muted { margin-top: 18px; font-size: 12px; color: #9ca3af; }
    </style>
</head>
<body>
    <main class="card">
        @if ($logo)
            <img src="{{ $logo }}" alt="" class="logo">
        @else
            <div class="mark">{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</div>
        @endif
        <div class="code">{{ $name }} · @yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a class="button" href="@yield('action_url', url()->previous() !== url()->current() ? url()->previous() : '/')">@yield('action', 'Go back')</a>
        <div class="muted">@yield('hint')</div>
    </main>
</body>
</html>
