<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class DestructiveDatabaseCommandGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('HOTLINE_ALLOW_DESTRUCTIVE_DB_COMMANDS');
        unset($_ENV['HOTLINE_ALLOW_DESTRUCTIVE_DB_COMMANDS'], $_SERVER['HOTLINE_ALLOW_DESTRUCTIVE_DB_COMMANDS']);

        parent::tearDown();
    }

    public function test_it_blocks_destructive_command_for_regular_database_name(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'pbb_hotline',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Blocked destructive database command "migrate:fresh" for database "pbb_hotline"');

        Event::dispatch(new CommandStarting('migrate:fresh', new ArrayInput([]), new BufferedOutput()));
    }

    public function test_it_allows_destructive_command_for_disposable_database_name(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'pbb_hotline_smoke',
        ]);

        Event::dispatch(new CommandStarting('migrate:fresh', new ArrayInput([]), new BufferedOutput()));

        $this->assertTrue(true);
    }

    public function test_it_allows_normal_migration_command_for_regular_database_name(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'pbb_hotline',
        ]);

        Event::dispatch(new CommandStarting('migrate', new ArrayInput([]), new BufferedOutput()));

        $this->assertTrue(true);
    }

    public function test_it_allows_explicitly_overridden_destructive_command(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'pbb_hotline',
        ]);

        putenv('HOTLINE_ALLOW_DESTRUCTIVE_DB_COMMANDS=true');
        $_ENV['HOTLINE_ALLOW_DESTRUCTIVE_DB_COMMANDS'] = 'true';
        $_SERVER['HOTLINE_ALLOW_DESTRUCTIVE_DB_COMMANDS'] = 'true';

        Event::dispatch(new CommandStarting('migrate:fresh', new ArrayInput([]), new BufferedOutput()));

        $this->assertTrue(true);
    }
}
