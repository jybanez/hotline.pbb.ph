<?php

namespace Tests\Feature\Realtime;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LegacyCallerEventUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_operator_can_log_legacy_caller_realtime_event_usage(): void
    {
        Log::spy();

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $this->actingAs($operator)
            ->postJson('/api/realtime/legacy-caller-events', [
                'surface' => 'operator',
                'event_type' => 'caller.call.answered',
                'canonical_event_type' => 'citizen.call.answered',
                'room' => 'presence.global.hotline',
            ])
            ->assertOk()
            ->assertJsonPath('logged', true);

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Hotline legacy caller Realtime event used.', \Mockery::on(
                fn (array $context): bool => ($context['surface'] ?? null) === 'operator'
                    && ($context['event_type'] ?? null) === 'caller.call.answered'
                    && ($context['canonical_event_type'] ?? null) === 'citizen.call.answered'
                    && ($context['room'] ?? null) === 'presence.global.hotline'
                    && (int) ($context['user_id'] ?? 0) === (int) $operator->id
                    && ($context['user_role'] ?? null) === UserRole::Operator->value
            ));
    }

    public function test_citizen_can_log_legacy_caller_realtime_event_usage(): void
    {
        Log::spy();

        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $this->actingAs($citizen)
            ->postJson('/api/realtime/legacy-caller-events', [
                'surface' => 'citizen',
                'event_type' => 'caller.operator.available.response',
                'canonical_event_type' => 'citizen.operator.available.response',
            ])
            ->assertOk()
            ->assertJsonPath('logged', true);

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Hotline legacy caller Realtime event used.', \Mockery::on(
                fn (array $context): bool => ($context['surface'] ?? null) === 'citizen'
                    && ($context['event_type'] ?? null) === 'caller.operator.available.response'
                    && ($context['canonical_event_type'] ?? null) === 'citizen.operator.available.response'
                    && ($context['room'] ?? null) === null
                    && (int) ($context['user_id'] ?? 0) === (int) $citizen->id
                    && ($context['user_role'] ?? null) === UserRole::Citizen->value
            ));
    }

    public function test_legacy_caller_realtime_event_usage_requires_legacy_event_type(): void
    {
        Log::spy();

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $this->actingAs($operator)
            ->postJson('/api/realtime/legacy-caller-events', [
                'surface' => 'operator',
                'event_type' => 'citizen.call.answered',
                'canonical_event_type' => 'citizen.call.answered',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_type');

        Log::shouldNotHaveReceived('info');
    }
}
