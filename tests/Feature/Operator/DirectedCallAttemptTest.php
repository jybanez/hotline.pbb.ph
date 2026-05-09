<?php

namespace Tests\Feature\Operator;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\AlertLevel;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectedCallAttemptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_operator_can_create_directed_call_attempt_when_realtime_selected_them(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        Incident::query()->create([
            'caller_id' => $caller->id,
            'actual_caller_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Deferred,
            'alert_level' => AlertLevel::Normal,
            'called_at' => now(),
        ]);

        $this->actingAs($operator)
            ->postJson('/api/operator/call-attempts', [
                'caller_id' => $caller->id,
                'caller_latitude' => 10.330507150390998,
                'caller_longitude' => 123.88256831994421,
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt.status', 'calling')
            ->assertJsonPath('operator_attempt.operator_id', $operator->id)
            ->assertJsonPath('operator_attempt.status', 'calling');
    }
}
