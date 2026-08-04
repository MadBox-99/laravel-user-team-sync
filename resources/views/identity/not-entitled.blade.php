<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('No access to this module') }}</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f8fafc;
               display: flex; align-items: center; justify-content: center;
               min-height: 100vh; margin: 0; color: #1e293b; }
        .card { background: #fff; padding: 2.5rem; border-radius: .75rem; max-width: 32rem;
                box-shadow: 0 1px 3px rgb(0 0 0 / .1); text-align: center; }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { margin: 0 0 1.5rem; line-height: 1.6; color: #475569; }
        a { display: inline-block; background: #4f46e5; color: #fff; padding: .625rem 1.25rem;
            border-radius: .5rem; text-decoration: none; font-weight: 500; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('No access to this module') }}</h1>
        <p>{{ __('Your account signed in successfully, but your subscription does not cover this module.') }}</p>
        <a href="{{ $subscribeUrl }}">{{ __('Manage subscription') }}</a>
    </div>
</body>
</html>
