<?php

namespace Tests\Feature\Operator;

use App\Domain\Calls\Models\CallAttempt;
use App\Domain\Calls\Models\CallAttemptOperatorAttempt;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use App\Support\Calls\CallRoutingService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AnswerCallAttemptStateTest extends TestCase
{
    use RefreshDatabase;

    private function route(): array
    {
        $caller = User::factory()->create(['role' => UserRole::Citizen]);
        $operator = User::factory()->create(['role' => UserRole::Operator]);
        $attempt = CallAttempt::query()->create(['citizen_id' => $caller->id, 'status' => CallStatus::Calling, 'started_at' => now()]);
        $route = $attempt->operatorAttempts()->create(['operator_id' => $operator->id, 'status' => CallStatus::Calling, 'started_at' => now(), 'created_at' => now()]);

        return [$caller, $operator, $attempt, $route];
    }

    private function assertRejected(User $operator, CallAttemptOperatorAttempt $route): void
    {
        try {
            app(CallRoutingService::class)->answerNewAttempt($operator, $route);
            $this->fail('A stale or unauthorized answer must be rejected.');
        } catch (RuntimeException $error) {
            $this->assertContains($error->getMessage(), ['This call attempt is no longer answerable.', 'You cannot answer this routed call.']);
        }
    }

    public function test_two_independently_preloaded_contexts_create_only_one_incident_and_session(): void
    {
        [$caller, $operator, , $route] = $this->route();
        $first = $route->fresh(['callAttempt']);
        $second = $route->fresh(['callAttempt']);
        app(CallRoutingService::class)->answerNewAttempt($operator, $first);
        $this->assertRejected($operator, $second);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('call_sessions', 1);
        $this->assertDatabaseCount('call_participants', 2);
        $this->assertDatabaseHas('call_participants', ['user_id' => $caller->id, 'participant_role' => 'citizen']);
        $this->assertDatabaseHas('call_participants', ['user_id' => $operator->id, 'participant_role' => 'operator']);
        $this->withoutMiddleware(VerifyCsrfToken::class)->actingAs($operator)
            ->postJson("/api/operator/call-attempt-operator-attempts/{$route->id}/answer")->assertStatus(409);
    }

    public function test_different_operator_routes_share_the_parent_answer_guard(): void
    {
        [, $operator, $attempt, $route] = $this->route();
        $other = User::factory()->create(['role' => UserRole::Operator]);
        $otherRoute = $attempt->operatorAttempts()->create(['operator_id' => $other->id, 'status' => CallStatus::Calling, 'started_at' => now(), 'created_at' => now()])->fresh(['callAttempt']);
        app(CallRoutingService::class)->answerNewAttempt($operator, $route);
        $this->assertRejected($other, $otherRoute);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('call_sessions', 1);
    }

    public function test_cancelled_and_timed_out_attempts_cannot_be_answered_from_stale_contexts(): void
    {
        foreach (['cancelAttempt', 'timeoutAttempt'] as $method) {
            [$caller, $operator, $attempt, $route] = $this->route();
            $stale = $route->fresh(['callAttempt']);
            app(CallRoutingService::class)->{$method}($caller, $attempt);
            $this->assertRejected($operator, $stale);
        }
        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('call_sessions', 0);
    }

    public function test_route_status_and_ownership_are_reloaded_from_storage(): void
    {
        [, $operator, , $route] = $this->route();
        $stale = $route->fresh(['callAttempt']);
        $route->update(['status' => CallStatus::Ended]);
        $this->assertRejected($operator, $stale);
        $route->update(['status' => CallStatus::Calling, 'operator_id' => User::factory()->create(['role' => UserRole::Operator])->id]);
        $this->assertRejected($operator, $stale);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_reconnect_reuses_the_incident_and_rechecks_incident_ownership(): void
    {
        [$caller, $operator, $attempt, $route] = $this->route();
        $incident = Incident::query()->create(['citizen_id' => $caller->id, 'operator_id' => $operator->id, 'actual_citizen_name' => $caller->name, 'status' => IncidentStatus::Deferred, 'alert_level' => 'Normal', 'called_at' => now()]);
        $stale = $route->fresh(['callAttempt']);
        $attempt->update(['incident_id' => $incident->id]);
        $other = User::factory()->create(['role' => UserRole::Operator]);
        $incident->update(['operator_id' => $other->id]);
        $this->assertRejected($operator, $stale);
        $incident->update(['operator_id' => $operator->id]);
        $result = app(CallRoutingService::class)->answerNewAttempt($operator, $stale);
        $this->assertSame($incident->id, $result['incident']->id);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('call_sessions', 1);
    }
}
