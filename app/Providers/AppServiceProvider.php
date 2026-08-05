<?php

namespace App\Providers;

use App\Support\Database\DestructiveDatabaseCommandGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        $this->registerDestructiveDatabaseCommandGuard();

        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
    }

    private function registerDestructiveDatabaseCommandGuard(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $guard = app(DestructiveDatabaseCommandGuard::class);

            if (! $guard->isBlockedCommand($event->command)) {
                return;
            }

            $connection = (string) config('database.default');
            $database = (string) config("database.connections.{$connection}.database", '');

            if (
                $guard->isExplicitlyAllowed(env('HOTLINE_ALLOW_DESTRUCTIVE_DB_COMMANDS'))
                || $guard->isDisposableDatabase($database)
            ) {
                return;
            }

            $message = sprintf(
                'Blocked destructive database command "%s" for database "%s". Use a disposable database name (*_test, *_smoke, *_rehearsal) or set HOTLINE_ALLOW_DESTRUCTIVE_DB_COMMANDS=true only after explicit approval and backup.',
                (string) $event->command,
                $database !== '' ? $database : '(not configured)',
            );

            $event->output?->writeln('<error>'.$message.'</error>');

            throw new RuntimeException($message);
        });
    }
}
