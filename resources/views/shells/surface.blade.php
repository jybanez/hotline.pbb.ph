@extends('layouts.base', ['vite' => $vite])

@section('title', $title)

@isset($pwaManifest)
    @section('head')
        <link rel="manifest" href="{{ $pwaManifest }}">
        <meta name="theme-color" content="{{ $themeColor ?? '#0f766e' }}">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="{{ $title }}">
        <link rel="icon" type="image/png" href="/favicon-192.png" sizes="192x192">
        <link rel="icon" type="image/png" href="/favicon-512.png" sizes="512x512">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @endsection
@endisset

@section('body')
    <div id="app"
         data-surface="{{ $surface }}"
         data-api-bootstrap-url="/api/bootstrap?surface={{ $surface }}">
    </div>
@endsection
