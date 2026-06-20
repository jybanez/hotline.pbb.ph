<?php

namespace Tests\Feature\Command;

use App\Support\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckHubHeartbeatsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_command_reads_policy_from_settings_service(): void
    {
        app(SettingsService::class)->set('heartbeat_enabled', false);

        $this->artisan('app:check-hub-heartbeats')
            ->expectsOutput('Heartbeat checks disabled.')
            ->assertSuccessful();
    }
}
