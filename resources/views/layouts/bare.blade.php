<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') — Recjota</title>
    {{-- CSS inline de propósito: estas telas precisam funcionar mesmo com os
         assets ausentes ou o cache de views quebrado. --}}
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        :root { color-scheme: light dark; --bg:#f8fafc; --fg:#0f172a; --muted:#64748b; --card:#fff;
                --line:#e2e8f0; --brand:#4f46e5; --ok:#047857; --bad:#be123c; --warn:#b45309; }
        @media (prefers-color-scheme: dark) {
            :root { --bg:#020617; --fg:#e2e8f0; --muted:#94a3b8; --card:#0f172a; --line:#1e293b;
                    --brand:#818cf8; --ok:#34d399; --bad:#fb7185; --warn:#fbbf24; }
        }
        body { margin:0; padding:24px 16px 64px; background:var(--bg); color:var(--fg);
               font:15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .wrap { max-width:720px; margin:0 auto; }
        h1 { font-size:1.35rem; margin:0 0 4px; letter-spacing:-.01em; }
        h2 { font-size:1rem; margin:24px 0 8px; }
        p.lead { color:var(--muted); margin:0 0 20px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; margin-bottom:16px; }
        .steps { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px; padding:0; list-style:none; font-size:13px; }
        .steps li { color:var(--muted); }
        .steps li[aria-current] { color:var(--brand); font-weight:600; }
        .steps li + li::before { content:"›"; margin-right:8px; color:var(--line); }
        label { display:block; font-weight:600; font-size:13px; margin:14px 0 4px; }
        input[type=text], input[type=email], input[type=password], input[type=url], input[type=number], select {
            width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px;
            background:var(--card); color:var(--fg); font-size:15px; }
        .hint { color:var(--muted); font-size:12.5px; margin:4px 0 0; }
        .grid { display:grid; gap:0 16px; grid-template-columns:1fr; }
        @media (min-width:560px) { .grid.two { grid-template-columns:1fr 1fr; } }
        button, .btn { display:inline-flex; align-items:center; justify-content:center; gap:8px;
            background:var(--brand); color:#fff; border:0; border-radius:8px; padding:11px 18px;
            font-size:15px; font-weight:600; cursor:pointer; text-decoration:none; margin-top:18px; }
        button.ghost, .btn.ghost { background:transparent; color:var(--fg); border:1px solid var(--line); }
        button.small { padding:7px 12px; font-size:13.5px; margin:0; }
        ul.checks { list-style:none; margin:0; padding:0; }
        ul.checks li { display:flex; gap:10px; padding:9px 0; border-bottom:1px solid var(--line); align-items:flex-start; }
        ul.checks li:last-child { border-bottom:0; }
        .mark { font-weight:700; flex:none; width:1.2em; }
        .ok .mark { color:var(--ok); } .bad .mark { color:var(--bad); }
        .item { font-weight:600; font-size:14px; }
        .detail { color:var(--muted); font-size:12.5px; }
        .fix { color:var(--warn); font-size:12.5px; margin-top:2px; }
        .alert { border-radius:8px; padding:12px 14px; font-size:14px; margin-bottom:16px; border:1px solid; }
        .alert.ok { border-color:var(--ok); color:var(--ok); }
        .alert.bad { border-color:var(--bad); color:var(--bad); }
        pre { background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:12px;
              overflow-x:auto; font-size:12.5px; white-space:pre-wrap; word-break:break-word; }
        code { font-size:12.5px; }
    </style>
</head>
<body>
    <div class="wrap">
        @yield('body')
    </div>
</body>
</html>
