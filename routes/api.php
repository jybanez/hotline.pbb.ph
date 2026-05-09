<?php

Route::middleware('web')->group(function (): void {
    require __DIR__.'/api/public.php';
    require __DIR__.'/api/session.php';
    require __DIR__.'/api/citizen.php';
    require __DIR__.'/api/operator.php';
    require __DIR__.'/api/command.php';
    require __DIR__.'/api/admin.php';
    require __DIR__.'/api/lookups.php';
    require __DIR__.'/api/incidents.php';
    require __DIR__.'/api/media.php';
    require __DIR__.'/api/realtime.php';
});

require __DIR__.'/api/internal.php';
