<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="data:,">
    <title>Sign in · {{ config('app.name') }} prototype</title>
    <style>
        /* Dark, like the prototype. */
        :root { --bg: #12151b; --surface: #191d25; --ink: #eceef2; --ink-2: #c6cad3; --muted: #a3a9b6; --line: #2b313c; --line-2: #343a46; --error: #f08b78; color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--ink); font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; -webkit-font-smoothing: antialiased; }
        main { width: 100%; max-width: 380px; display: flex; flex-direction: column; gap: 20px; }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 24px; display: flex; flex-direction: column; gap: 16px; box-shadow: 0 1px 2px rgb(0 0 0 / .05), 0 10px 30px rgb(0 0 0 / .06); }
        h1 { margin: 0; font-size: 18px; line-height: 1.3; }
        .site { margin: 0 0 2px; font-size: 12px; color: var(--muted); }
        p { margin: 0; color: var(--muted); }
        form { display: flex; flex-direction: column; gap: 12px; }
        label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; font-weight: 600; }
        input { font: inherit; color: inherit; background: var(--surface); border: 1px solid var(--line-2); border-radius: 8px; padding: 8px 10px; }
        input:focus { outline: none; border-color: var(--ink); box-shadow: 0 0 0 1px var(--ink); }
        input[aria-invalid="true"] { border-color: var(--error); }
        .error { font-size: 13px; font-weight: 500; color: var(--error); }
        button { font: inherit; font-weight: 600; font-size: 13px; cursor: pointer; border: 1px solid var(--ink); border-radius: 8px; padding: 10px 14px; background: var(--ink); color: var(--surface); margin-top: 4px; }
        button:focus-visible { outline: 2px solid var(--ink); outline-offset: 2px; }
        .note { font-size: 12px; text-align: center; }
        footer { display: flex; align-items: center; justify-content: center; gap: 10px; font-size: 12px; color: var(--muted); }
        footer svg { width: 32px; height: 32px; }
    </style>
</head>
<body>
    <main>
        <div class="card">
            <div>
                <p class="site">{{ config('app.name') }}</p>
                <h1>Website prototype</h1>
            </div>
            <p>
                @if ($needsEmail)
                    Use the email address you were invited with{{ $needsPassword ? ', and the password you were given' : '' }}.
                @else
                    Enter your name{{ $needsPassword ? ' and the password you were given' : '' }}.
                @endif
            </p>
            <form method="post" action="/prototype" novalidate>
                @csrf
                @if ($version)<input type="hidden" name="version" value="{{ $version }}">@endif
                @if ($comment ?? null)<input type="hidden" name="comment" value="{{ $comment }}">@endif
                @if ($needsEmail)
                    <label>Email address
                        <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="254" required @error('email') aria-invalid="true" aria-describedby="email-error" @enderror autofocus>
                        @error('email')<span class="error" id="email-error">{{ $message }}</span>@enderror
                    </label>
                @else
                    <label>Your name
                        <input type="text" name="name" value="{{ old('name') }}" autocomplete="name" maxlength="80" required @error('name') aria-invalid="true" aria-describedby="name-error" @enderror autofocus>
                        @error('name')<span class="error" id="name-error">{{ $message }}</span>@enderror
                    </label>
                @endif
                @if ($needsPassword)
                    <label>Password
                        <input type="password" name="password" autocomplete="current-password" required @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                        @error('password')<span class="error" id="password-error">{{ $message }}</span>@enderror
                    </label>
                @endif
                <button type="submit">View the prototype</button>
            </form>
            <p class="note">This browser stays signed in for 30 days.</p>
        </div>
        @if ($agency['name'] !== '')
            <footer>
                @if ($agency['logo_dark'] !== '')<span aria-hidden="true">{!! $agency['logo_dark'] !!}</span>@endif
                <span>Prepared by {{ $agency['name'] }}</span>
            </footer>
        @endif
    </main>
</body>
</html>
