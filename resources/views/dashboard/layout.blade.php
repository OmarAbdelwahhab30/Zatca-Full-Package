<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'ZATCA Dashboard')</title>
    <style>
        :root { --b:#1e3a5f; --g:#1b7f3b; --r:#b3261e; --y:#9a6700; --bg:#f4f6f9; --line:#dde2ea; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, Segoe UI, Arial, sans-serif; margin:0; background:var(--bg); color:#1c2430; font-size:14px; }
        header { background:var(--b); color:#fff; padding:0 24px; display:flex; align-items:center; gap:28px; }
        header h1 { font-size:17px; margin:0; padding:14px 0; }
        header a { color:#cfe0f5; text-decoration:none; padding:15px 4px; }
        header a.on, header a:hover { color:#fff; border-bottom:3px solid #fff; }
        main { max-width:1180px; margin:22px auto; padding:0 20px; }
        .card { background:#fff; border:1px solid var(--line); border-radius:8px; padding:16px 18px; margin-bottom:18px; }
        .card h2 { margin:0 0 12px; font-size:16px; }
        .grid { display:grid; gap:14px; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); }
        .stat { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px 16px; }
        .stat b { display:block; font-size:26px; margin-top:4px; }
        table { width:100%; border-collapse:collapse; }
        th, td { text-align:left; padding:7px 8px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { font-size:12px; text-transform:uppercase; color:#5a6678; }
        .badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:12px; background:#e8ecf2; }
        .ok { background:#dcf3e3; color:var(--g); } .bad { background:#fbe0de; color:var(--r); } .warn { background:#fff1cc; color:var(--y); }
        .flash { padding:11px 14px; border-radius:6px; margin-bottom:16px; white-space:pre-wrap; word-break:break-word; }
        .flash.ok { background:#dcf3e3; color:#14532d; } .flash.error { background:#fbe0de; color:#7a1d17; }
        button, .btn { background:var(--b); color:#fff; border:0; border-radius:6px; padding:9px 14px; cursor:pointer; font-size:14px; text-decoration:none; display:inline-block; }
        button.b2c { background:#1b6ca8; } button.b2b { background:#6a3d9a; }
        button.sec, .btn.sec { background:#e8ecf2; color:#1c2430; }
        button:disabled { opacity:.5; cursor:not-allowed; }
        input[type=text] { padding:8px; border:1px solid var(--line); border-radius:6px; }
        pre { background:#0f1720; color:#d6e2f0; padding:12px; border-radius:6px; overflow:auto; max-height:380px; font-size:12px; }
        .row { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .scen { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:12px; }
        .scen form button { width:100%; padding:14px; font-size:15px; }
        .muted { color:#5a6678; font-size:12px; }
        code { background:#eef1f6; padding:1px 5px; border-radius:4px; }
    </style>
</head>
<body>
<header>
    <h1>ZATCA Dashboard</h1>
    <a href="{{ route('zatca.dashboard.overview') }}" class="{{ request()->routeIs('zatca.dashboard.overview') ? 'on' : '' }}">Overview</a>
    <a href="{{ route('zatca.dashboard.onboarding') }}" class="{{ request()->routeIs('zatca.dashboard.onboarding') ? 'on' : '' }}">Onboarding</a>
    <a href="{{ route('zatca.dashboard.invoices.index') }}" class="{{ request()->routeIs('zatca.dashboard.invoices.*') ? 'on' : '' }}">Invoices</a>
    <span style="margin-left:auto" class="badge">{{ strtoupper(app(\Zatca\State\EgsRegistry::class)->primary()->environment->value) }}</span>
</header>
<main>
    @if (session('ok')) <div class="flash ok">{{ session('ok') }}</div> @endif
    @if (session('error')) <div class="flash error">{{ session('error') }}</div> @endif
    @if ($errors->any()) <div class="flash error">{{ $errors->first() }}</div> @endif
    @yield('content')
</main>
</body>
</html>
