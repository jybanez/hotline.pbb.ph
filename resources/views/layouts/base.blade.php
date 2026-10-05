<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name', 'PBB Hotline Beta'))</title>
    @yield('head')
    @vite($vite)
</head>
<body class="ui-actions-borderless @yield('body_class')">
    @yield('body')
</body>
</html>
