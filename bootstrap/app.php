<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        \App\Console\Commands\ExportIncidentRelay::class,
        \App\Console\Commands\QueueIncidentRelay::class,
        \App\Console\Commands\ProcessIncidentRelayOutbox::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            \App\Http\Middleware\ConfigureCriticalSessionLifetime::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\CleanupLegacyCookies::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'account.admin' => \App\Http\Middleware\VerifyAccountAdminService::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
