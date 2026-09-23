<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Admin sign in - {{ config('shop.name') }}</title>
    <link rel="stylesheet" href="/css/shop.css">
</head>
<body>
<form method="POST" action="{{ route('admin.login') }}" class="login stack">@csrf
    <h1 style="font: 500 28px var(--serif); margin: 0 0 8px">Admin</h1>
    <p class="hint">Demo login, read-only: <b>demo@emberandoak.test</b> with the password shown on codeinchrome.com.</p>
    <label for="email">Email</label>
    <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
    <label for="password">Password</label>
    <input id="password" type="password" name="password" required autocomplete="current-password">
    @error('email')<p class="flash error">{{ $message }}</p>@enderror
    <p><button class="btn" style="margin-top: 16px">Sign in</button></p>
</form>
</body>
</html>
