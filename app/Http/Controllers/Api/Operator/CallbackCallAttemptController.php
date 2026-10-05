<?php

namespace App\Http\Controllers\Api\Operator;

use App\Domain\Calls\Models\CallAttempt;
use App\Domain\Calls\Models\CallSession;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Support\Calls\CallParticipantReservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CallbackCallAttemptController extends Controller
{
    public function __construct(private readonly CallParticipantReservation $reservations) {}

    public function store(Request $request, Incident $incident)
    {
        $attempt = DB::transaction(function () use ($request, $incident) {
            $this->reservations->lock((int) $request->user()->id, (int) $incident->citizen_id);
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

        return response()->json($this->state($attempt), 201);
    }

    public function answer(Request $request, CallAttempt $attempt)
    {
        $result = DB::transaction(function () use ($request, $attempt) {
            $this->reservations->lock((int) $request->user()->id, (int) $attempt->citizen_id);
            $incident = Incident::query()->lockForUpdate()->findOrFail($attempt->incident_id);
            abort_unless((int) $incident->operator_id === (int) $request->user()->id, 403);
            abort_unless(in_array($incident->status, [IncidentStatus::Active, IncidentStatus::Deferred], true), 409);
            $this->reservations->expire((int) $request->user()->id, (int) $attempt->citizen_id);
            $attempt = CallAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($attempt->callback && $attempt->outcome?->value === 'timed_out') {
                return null;
            }
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
        abort_if($result === null, 409, 'Callback has expired.');
        $result['incident'] = app(\App\Support\Incidents\IncidentPayloadBuilder::class)->buildWorkbenchPayload($result['incident'], $request->user(), includeLegacyAliases: false);

        return response()->json($result);
    }

    public function cancel(Request $request, CallAttempt $attempt)
    {
        $validated = $request->validate(['outcome' => ['sometimes', 'in:cancelled_by_operator,declined_by_citizen,timed_out']]);
        $attempt = DB::transaction(function () use ($request, $attempt, $validated) {
            $this->reservations->lock((int) $request->user()->id, (int) $attempt->citizen_id);
            $incident = Incident::query()->lockForUpdate()->findOrFail($attempt->incident_id);
            abort_unless((int) $incident->operator_id === (int) $request->user()->id, 403);
            $this->reservations->expire((int) $request->user()->id, (int) $attempt->citizen_id);
            $attempt = CallAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            abort_unless($attempt->callback, 404);
            if ($attempt->status === CallStatus::Calling) {
                $attempt->update(['status' => CallStatus::Ended,
                    'outcome' => $validated['outcome'] ?? 'cancelled_by_operator', 'ended_at' => now()]);
            }

            return $attempt->fresh();
        });

        return response()->json($this->state($attempt));
    }

    public function show(Request $request, CallAttempt $attempt)
    {
        abort_unless($attempt->callback && (int) $attempt->citizen_id === (int) $request->user()->id, 404);
        $attempt = DB::transaction(function () use ($attempt) {
            $operatorId = (int) $attempt->incident?->operator_id;
            $this->reservations->lock($operatorId, (int) $attempt->citizen_id);
            $this->reservations->expire($operatorId, (int) $attempt->citizen_id);

            return $attempt->fresh();
        });
        abort_unless($attempt->status === CallStatus::Calling, 409, 'Callback is no longer ringing.');

        return response()->json(['attempt' => $attempt, 'operator_name' => $attempt->incident?->operator?->name ?? 'Operator']);
    }

    public function status(Request $request, CallAttempt $attempt)
    {
        $attempt = DB::transaction(function () use ($request, $attempt) {
            $this->reservations->lock((int) $request->user()->id, (int) $attempt->citizen_id);
            $incident = Incident::query()->lockForUpdate()->findOrFail($attempt->incident_id);
            abort_unless($attempt->callback && (int) $incident->operator_id === (int) $request->user()->id, 403);
            $this->reservations->expire((int) $request->user()->id, (int) $attempt->citizen_id);

            return $attempt->fresh();
        });

        return response()->json($this->state($attempt));
    }

    private function state(CallAttempt $attempt): array
    {
        return ['attempt' => $attempt, 'expires_at' => $attempt->started_at?->copy()->addSeconds(CallParticipantReservation::CALLBACK_RING_SECONDS),
            'call_session' => $attempt->outcome?->value === 'answered'
                ? CallSession::where('incident_id', $attempt->incident_id)->where('citizen_id', $attempt->citizen_id)->where('started_at', $attempt->started_at)->latest('id')->first() : null];
    }

    private function assertParticipantsAvailable(Incident $incident, Request $request, ?int $exceptAttemptId = null): void
    {
        $this->reservations->expire((int) $request->user()->id, (int) $incident->citizen_id);
        try {
            $this->reservations->assertAvailable((int) $request->user()->id, (int) $incident->citizen_id, $exceptAttemptId);
        } catch (\RuntimeException $error) {
            abort(409, $error->getMessage());
        }
    }
}
