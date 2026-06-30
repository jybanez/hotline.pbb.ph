<?php

use App\Http\Controllers\AccountSsoController;

require __DIR__.'/web/public.php';
require __DIR__.'/web/citizen.php';
require __DIR__.'/web/operator.php';
require __DIR__.'/web/command.php';
require __DIR__.'/web/admin.php';

\Illuminate\Support\Facades\Route::get('/auth/account/redirect', [AccountSsoController::class, 'redirect'])->name('account.redirect');
\Illuminate\Support\Facades\Route::get('/auth/account/callback', [AccountSsoController::class, 'callback'])->name('account.callback');
\Illuminate\Support\Facades\Route::match(['GET', 'POST'], '/auth/logout', [AccountSsoController::class, 'logout'])->name('account.logout');

\Illuminate\Support\Facades\Route::view('/unauthorized', 'pages.unauthorized')->name('unauthorized');
