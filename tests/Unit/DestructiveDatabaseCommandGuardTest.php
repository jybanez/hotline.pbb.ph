<?php

namespace Tests\Unit;

use App\Support\Database\DestructiveDatabaseCommandGuard;
use PHPUnit\Framework\TestCase;

class DestructiveDatabaseCommandGuardTest extends TestCase
{
    public function test_it_identifies_destructive_database_commands(): void
    {
        $guard = new DestructiveDatabaseCommandGuard();

        $this->assertTrue($guard->isBlockedCommand('migrate:fresh'));
        $this->assertTrue($guard->isBlockedCommand('db:wipe'));
        $this->assertTrue($guard->isBlockedCommand('schema:load'));
        $this->assertFalse($guard->isBlockedCommand('migrate'));
        $this->assertFalse($guard->isBlockedCommand('migrate:status'));
    }

    public function test_it_allows_only_disposable_database_names(): void
    {
        $guard = new DestructiveDatabaseCommandGuard();

        $this->assertTrue($guard->isDisposableDatabase(':memory:'));
        $this->assertTrue($guard->isDisposableDatabase('pbb_hotline_test'));
        $this->assertTrue($guard->isDisposableDatabase('pbb_hotline_smoke'));
        $this->assertTrue($guard->isDisposableDatabase('pbb_hotline_rehearsal'));
        $this->assertFalse($guard->isDisposableDatabase('pbb_hotline'));
        $this->assertFalse($guard->isDisposableDatabase('pbb_hotline_rc'));
    }

    public function test_it_requires_truthy_explicit_override(): void
    {
        $guard = new DestructiveDatabaseCommandGuard();

        $this->assertTrue($guard->isExplicitlyAllowed('true'));
        $this->assertTrue($guard->isExplicitlyAllowed('1'));
        $this->assertFalse($guard->isExplicitlyAllowed('false'));
        $this->assertFalse($guard->isExplicitlyAllowed(null));
    }
}
