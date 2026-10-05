<?php

namespace App\Http\Controllers\Api\Operator;

use App\Domain\Calls\Models\CallAttempt;
use App\Domain\Calls\Models\CallSession;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CallbackCallAttemptController extends Controller
{
    public function store(Request $request, Incident $incident)
    {
        $attempt = DB::transaction(function () use ($request, $incident) {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            abort_unless((int) $incident->operator_id === (int) $request->user()->id, 403);
            abort_unless(in_array($incident->status, [IncidentStatus::Active, IncidentStatus::Deferred], true), 409, 'This incident is no longer open.');
            $citizen = $incident->citizen;
            abort_unless($citizen && $citizen->role->isCitizen() && $citizen->status === UserStatus::Active, 409, 'Citizen is unavailable.');
            $this->assertParticipantsAvailable($incident, $request);
            abort_if(CallAttempt::where('incident_id', $incident->id)->where('status', CallStatus::Calling)->exists()
                || CallSession::where('incident_id', $incident->id)->whereIn('status', [CallStatus::Calling, CallStatus::InProgress])->exists(), 409, 'A call is already in progress.');

            return CallAttempt::create(['citizen_id' => $citizen->id, 'incident_id' => $incident->id,
                'callback' => true, 'status' => CallStatus::Calling, 'started_at' => now()]);
        });

        return response()->json(['attempt' => $attempt], 201);
    }

    public function answer(Request $request, CallAttempt $attempt)
    {
        $result = DB::transaction(function () use ($request, $attempt) {
            $incident = Incident::query()->lockForUpdate()->findOrFail($attempt->incident_id);
            abort_unless((int) $incident->operator_id === (int) $request->user()->id, 403);
            abort_unless(in_array($incident->status, [IncidentStatus::Active, IncidentStatus::Deferred], true), 409);
            $attempt = CallAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            abort_unless($attempt->callback && $attempt->status === CallStatus::Calling
                && (int) $attempt->citizen_id === (int) $incident->citizen_id, 409, 'Callback is no longer answerable.');
            $citizen = $incident->citizen;
            abort_unless($citizen && $citizen->role->isCitizen() && $citizen->status === UserStatus::Active, 409, 'Citizen is unavailable.');
            $this->assertParticipantsAvailable($incident, $request, $attempt->id);
            abort_if(CallSession::where('incident_id', $incident->id)->whereIn('status', [CallStatus::Calling, CallStatus::InProgress])->exists(), 409);
            $attempt->update(['status' => CallStatus::Ended, 'outcome' => 'answered', 'answered_at' => now(), 'ended_at' => now()]);
            $session = CallSession::create(['incident_id' => $incident->id, 'citizen_id' => $attempt->citizen_id,
                'status' => CallStatus::InProgress, 'started_at' => $attempt->started_at, 'answered_at' => null]);
            foreach ([$attempt->citizen_id => 'citizen', $request->user()->id => 'operator'] as $userId => $role) {
                \App\Domain\Calls\Models\CallParticipant::create(['call_session_id' => $session->id, 'user_id' => $userId,
                    'participant_role' => $role, 'joined_at' => now()]);
            }

            return ['attempt' => $attempt->fresh(), 'incident' => $incident->fresh(), 'call_session' => $session->fresh(['participants'])];
        });
        $result['incident'] = app(\App\Support\Incidents\IncidentPayloadBuilder::class)->buildWorkbenchPayload($result['incident'], $request->user(), includeLegacyAliases: false);

        return response()->json($result);
    }

    public function cancel(Request $request, CallAttempt $attempt)
    {
        $validated = $request->validate(['outcome' => ['sometimes', 'in:cancelled_by_operator,declined_by_citizen,timed_out']]);
        DB::transaction(function () use ($request, $attempt, $validated) {
            $incident = Incident::query()->lockForUpdate()->findOrFail($attempt->incident_id);
            abort_unless((int) $incident->operator_id === (int) $request->user()->id, 403);
            $attempt = CallAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            abort_unless($attempt->callback, 404);
            if ($attempt->status === CallStatus::Calling) {
                $attempt->update(['status' => CallStatus::Ended,
                    'outcome' => $validated['outcome'] ?? 'cancelled_by_operator', 'ended_at' => now()]);
            }
        });

        return response()->json(['ok' => true]);
    }

    public function show(Request $request, CallAttempt $attempt)
    {
        abort_unless($attempt->callback && (int) $attempt->citizen_id === (int) $request->user()->id, 404);
        abort_unless($attempt->status === CallStatus::Calling, 409, 'Callback is no longer ringing.');

        return response()->json(['attempt' => $attempt, 'operator_name' => $attempt->incident?->operator?->name ?? 'Operator']);
    }

    private function assertParticipantsAvailable(Incident $incident, Request $request, ?int $exceptAttemptId = null): void
    {
        $operatorId = (int) $request->user()->id;
        $citizenId = (int) $incident->citizen_id;
        // Serialize callbacks involving either person, including different incidents.
        \App\Domain\Users\Models\User::query()->whereIn('id', [$operatorId, $citizenId])->orderBy('id')->lockForUpdate()->get();
        $pending = CallAttempt::query()->where('status', CallStatus::Calling)
            ->when($exceptAttemptId, fn ($query) => $query->whereKeyNot($exceptAttemptId))
            ->where(fn ($query) => $query->where('citizen_id', $citizenId)
                ->orWhereHas('operatorAttempts', fn ($route) => $route->where('operator_id', $operatorId)->where('status', CallStatus::Calling))
                ->orWhere(fn ($callback) => $callback->where('callback', true)->whereHas('incident', fn ($item) => $item->where('operator_id', $operatorId))))
            ->exists();
        $active = CallSession::query()->whereIn('status', [CallStatus::Calling, CallStatus::InProgress])
            ->where(fn ($query) => $query->where('citizen_id', $citizenId)
                ->orWhereHas('participants', fn ($participant) => $participant->where('user_id', $operatorId)->whereNull('left_at')))
            ->exists();
        abort_if($pending || $active, 409, 'A participant already has an active or pending call.');
    }
}
