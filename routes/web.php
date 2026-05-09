<?php

require __DIR__.'/web/public.php';
require __DIR__.'/web/citizen.php';
require __DIR__.'/web/operator.php';
require __DIR__.'/web/command.php';
require __DIR__.'/web/admin.php';

\Illuminate\Support\Facades\Route::view('/unauthorized', 'pages.unauthorized')->name('unauthorized');
