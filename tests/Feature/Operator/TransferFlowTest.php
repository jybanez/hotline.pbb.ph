<?php

namespace Tests\Feature\Operator;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransferFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_operator_can_request_and_target_can_reject_transfer(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $fromOperator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $toOperator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $caller->id,
            'actual_caller_name' => $caller->name,
            'operator_id' => $fromOperator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($fromOperator)
            ->postJson("/api/operator/incidents/{$incidentId}/transfers", [
                'to_operator_id' => $toOperator->id,
                'reason' => 'Please take over.',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('transfer.status', 'requested');

        $transferId = $response->json('transfer.id');

        $this->actingAs($toOperator)
            ->postJson("/api/operator/transfers/{$transferId}/reject")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('transfer.status', 'rejected');

        $this->assertDatabaseHas('incidents', [
            'id' => $incidentId,
            'operator_id' => $fromOperator->id,
        ]);
    }

    public function test_operator_can_request_and_target_can_accept_transfer(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $fromOperator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $toOperator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $caller->id,
            'actual_caller_name' => $caller->name,
            'operator_id' => $fromOperator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($fromOperator)
            ->postJson("/api/operator/incidents/{$incidentId}/transfers", [
                'to_operator_id' => $toOperator->id,
                'reason' => 'Escalating to available operator.',
            ]);

        $transferId = $response->json('transfer.id');

        $this->actingAs($toOperator)
            ->postJson("/api/operator/transfers/{$transferId}/accept")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('transfer.status', 'accepted');

        $this->assertDatabaseHas('incidents', [
            'id' => $incidentId,
            'operator_id' => $toOperator->id,
        ]);
    }
}
