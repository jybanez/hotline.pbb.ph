<?php

namespace App\Support\Realtime;

use App\Domain\Calls\Models\CallSession;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Support\Settings\SettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use RuntimeException;

require_once __DIR__.'/Sdk/pbb_realtime_backend_sdk.php';

class RealtimeAdmissionService
{
    private const REALTIME_AUDIENCE = 'pbb-realtime';

    private const HOTLINE_DISCOVERY_ROOM = 'presence.global.hotline';

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forCitizen(User $user, string $contextType, int $contextId): array
    {
        return match ($contextType) {
            'surface_runtime' => $this->buildSurfaceAdmission(
                user: $user,
                projectCode: $this->realtimeProjectCode('caller', 'prj_hotline_caller'),
                rooms: $this->citizenSurfaceRooms($user),
                capabilities: [
                    'session.connect',
                    'room.join',
                    'event.publish',
                    'presence.subscribe',
                ],
            ),
            'settings_stream' => $this->buildSettingsRoomAdmission(
                user: $user,
                projectCode: $this->realtimeProjectCode('caller', 'prj_hotline_caller'),
                room: RealtimeEventPublishService::SETTINGS_ROOM,
                capabilities: [
                    'session.connect',
                    'room.join',
                    'presence.subscribe',
                ],
            ),
            'incident_chat' => $this->buildIncidentChatAdmission(
                user: $user,
                projectCode: $this->realtimeProjectCode('caller', 'prj_hotline_caller'),
                incident: $this->callerIncident($user, $contextId),
                capabilities: [
                    'session.connect',
                    'room.join',
                    'chat.publish',
                    'chat.subscribe',
                ],
            ),
            'call_session' => $this->buildCallSessionAdmission(
                user: $user,
                projectCode: $this->realtimeProjectCode('caller', 'prj_hotline_caller'),
                callSession: $this->callerCallSession($user, $contextId),
                capabilities: [
                    'session.connect',
                    'room.join',
                    'chat.publish',
                    'chat.subscribe',
                    'call.signal',
                ],
            ),
            'call_discovery' => $this->buildPresenceAdmission(
                user: $user,
                projectCode: $this->realtimeProjectCode('caller', 'prj_hotline_caller'),
                room: self::HOTLINE_DISCOVERY_ROOM,
                capabilities: [
                    'session.connect',
                    'room.join',
                    'event.publish',
                    'presence.subscribe',
                ],
            ),
            default => throw new AuthorizationException('Realtime citizen admission is not allowed for this context.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function forOperator(User $user, string $contextType, int $contextId): array
    {
        return match ($contextType) {
            'surface_runtime' => $this->buildSurfaceAdmission(
                user: $user,
                projectCode: $this->realtimeProjectCode('operator', 'prj_hotline_operator'),
                rooms: [
                    RealtimeEventPublishService::SETTINGS_ROOM,
                    RealtimeEventPublishService::BROADCAST_ROOM,
                    self::HOTLINE_DISCOVERY_ROOM,
                ],
                capabilities: [
                    'session.connect',
                    'room.join',
                    'event.publish',
                    'presence.subscribe',
                    'presence.publish',
                ],
            ),
            'settings_stream' => $this->buildSettingsRoomAdmission(
                user: $user,
                projectCode: $this->realtimeProjectCode('operator', 'prj_hotline_operator'),
                room: RealtimeEventPublishService::SETTINGS_ROOM,
                capabilities: [
                    'session.connect',
                    'room.join',
                ],
            ),
            'incident_chat' => $this->buildIncidentChatAdmission(
                user: $user,
                projectCode: $this->realtimeProjectCode('operator', 'prj_hotline_operator'),
                incident: $this->operatorIncident($user, $contextId),
                capabilities: [
                    'session.connect',
                    'room.join',
                    'chat.publish',
                    'chat.subscribe',
                ],
            ),
            'call_session' => $this->buildCallSessionAdmission(
                user: $user,
                projectCode: $this->realtimeProjectCode('operator', 'prj_hotline_operator'),
                callSession: $this->operatorCallSession($user, $contextId),
                capabilities: [
                    'session.connect',
                    'room.join',
                    'chat.publish',
                    'chat.subscribe',
                    'call.signal',
                ],
            ),
            'media_ingest' => $this->buildSingleRoomAdmission(
                user: $user,
                projectCode: $this->realtimeProjectCode('media_ingest', 'prj_hotline_media_ingest'),
                room: $this->callSessionRoom($this->operatorCallSession($user, $contextId)->id),
                capabilities: [
                    'session.connect',
                    'room.join',
                ],
                allowedRoomPrefixes: [
                    'call.session.',
                ],
            ),
            'call_discovery' => $this->buildPresenceAdmission(
                user: $user,
                projectCode: $this->realtimeProjectCode('operator', 'prj_hotline_operator'),
                room: self::HOTLINE_DISCOVERY_ROOM,
                capabilities: [
                    'session.connect',
                    'room.join',
                    'event.publish',
                    'presence.publish',
                ],
            ),
            'dashboard_presence' => $this->buildPresenceAdmission(
                user: $user,
                projectCode: $this->realtimeProjectCode('operator', 'prj_hotline_operator'),
                room: 'presence.workspace.operator',
                capabilities: [
                    'session.connect',
                    'room.join',
                    'presence.subscribe',
                    'presence.publish',
                ],
            ),
            default => throw new AuthorizationException('Realtime operator admission is not allowed for this context.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function forCommand(User $user, string $contextType, int $contextId): array
    {
        return match ($contextType) {
            'surface_runtime' => $this->buildSurfaceAdmission(
                user: $user,
                projectCode: $this->realtimeProjectCode('command', 'prj_hotline_command'),
                rooms: [
                    RealtimeEventPublishService::SETTINGS_ROOM,
                    RealtimeEventPublishService::BROADCAST_ROOM,
                    self::HOTLINE_DISCOVERY_ROOM,
                ],
                capabilities: [
                    'session.connect',
                    'room.join',
                    'presence.subscribe',
                ],
            ),
            'settings_stream' => $this->buildSettingsRoomAdmission(
                user: $user,
                projectCode: $this->realtimeProjectCode('command', 'prj_hotline_command'),
                room: RealtimeEventPublishService::SETTINGS_ROOM,
                capabilities: [
                    'session.connect',
                    'room.join',
                ],
            ),
            default => throw new AuthorizationException('Realtime command admission is not allowed for this context.'),
        };
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    private function buildSettingsRoomAdmission(User $user, string $projectCode, string $room, array $capabilities): array
    {
        return $this->buildSingleRoomAdmission(
            user: $user,
            projectCode: $projectCode,
            room: $room,
            capabilities: $capabilities,
            allowedRoomPrefixes: [
                'hotline.settings.',
            ],
        );
    }

    /**
     * @param  array<int, string>  $capabilities
     * @param  array<int, string>  $allowedRoomPrefixes
     * @return array<string, mixed>
     */
    private function buildSingleRoomAdmission(User $user, string $projectCode, string $room, array $capabilities, array $allowedRoomPrefixes): array
    {
        $config = $this->config();
        $builder = new \RealtimeTokenBuilder($config);
        $claims = $builder->forChatSession($this->baseContext(
            user: $user,
            projectCode: $projectCode,
            room: $room,
            capabilities: $capabilities,
            allowedRoomPrefixes: $allowedRoomPrefixes,
        ));

        $claims['allowed_rooms'] = [$room];
        $claims['allowed_room_prefixes'] = array_values(array_unique($allowedRoomPrefixes));
        $token = $builder->sign($claims);

        return [
            'token' => $token,
            'websocket_url' => $config->websocketUrl,
            'app_code' => $this->realtimeAppCode(),
            'project_code' => $projectCode,
            'room' => $room,
            'expires_at' => gmdate('c', (int) $claims['exp']),
            'session' => [
                'token_id' => $claims['jti'],
                'user_id' => $claims['user_id'],
                'display_name' => $claims['display_name'],
                'capabilities' => $claims['capabilities'],
                'allowed_rooms' => $claims['allowed_rooms'],
                'allowed_room_prefixes' => $claims['allowed_room_prefixes'],
                'attachment_policy' => $claims['attachment_policy'],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $rooms
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    private function buildSurfaceAdmission(User $user, string $projectCode, array $rooms, array $capabilities): array
    {
        $config = $this->config();
        $builder = new \RealtimeTokenBuilder($config);
        $claims = $builder->forChatSession($this->baseContext(
            user: $user,
            projectCode: $projectCode,
            room: $rooms[0] ?? RealtimeEventPublishService::SETTINGS_ROOM,
            capabilities: $capabilities,
            allowedRoomPrefixes: [
                'hotline.settings.',
                'hotline.broadcast.',
                RealtimeEventPublishService::INCIDENT_MEDIA_ROOM_PREFIX,
                'presence.global.',
            ],
        ));

        $claims['allowed_rooms'] = array_values(array_unique($rooms));
        $claims['allowed_room_prefixes'] = [
            'hotline.settings.',
            'hotline.broadcast.',
            RealtimeEventPublishService::INCIDENT_MEDIA_ROOM_PREFIX,
            'presence.global.',
        ];
        $token = $builder->sign($claims);

        return [
            'token' => $token,
            'websocket_url' => $config->websocketUrl,
            'app_code' => $this->realtimeAppCode(),
            'project_code' => $projectCode,
            'rooms' => $claims['allowed_rooms'],
            'expires_at' => gmdate('c', (int) $claims['exp']),
            'session' => [
                'token_id' => $claims['jti'],
                'user_id' => $claims['user_id'],
                'display_name' => $claims['display_name'],
                'capabilities' => $claims['capabilities'],
                'allowed_rooms' => $claims['allowed_rooms'],
                'allowed_room_prefixes' => $claims['allowed_room_prefixes'],
                'attachment_policy' => $claims['attachment_policy'],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function citizenSurfaceRooms(User $user): array
    {
        $rooms = [
            RealtimeEventPublishService::SETTINGS_ROOM,
            RealtimeEventPublishService::BROADCAST_ROOM,
            self::HOTLINE_DISCOVERY_ROOM,
        ];

        $currentIncident = Incident::query()
            ->where('citizen_id', $user->id)
            ->whereIn('status', ['Active', 'Deferred'])
            ->latest('id')
            ->first();

        if ($currentIncident) {
            $rooms[] = $this->incidentChatRoom($currentIncident->id);
        }

        return array_values(array_unique($rooms));
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    private function buildIncidentChatAdmission(User $user, string $projectCode, Incident $incident, array $capabilities): array
    {
        $room = $this->incidentChatRoom($incident->id);
        $admission = new \RealtimeAdmission($this->config());

        return $admission->buildAdmission($this->baseContext(
            user: $user,
            projectCode: $projectCode,
            room: $room,
            capabilities: $capabilities,
        ));
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    private function buildPresenceAdmission(User $user, string $projectCode, string $room, array $capabilities): array
    {
        $config = $this->config();
        $builder = new \RealtimeTokenBuilder($config);
        $claims = $builder->forPresenceSession($this->baseContext(
            user: $user,
            projectCode: $projectCode,
            room: $room,
            capabilities: $capabilities,
            presence: true,
        ));

        $claims['allowed_rooms'] = [$room];
        $token = $builder->sign($claims);

        return [
            'token' => $token,
            'websocket_url' => $config->websocketUrl,
            'app_code' => $this->realtimeAppCode(),
            'project_code' => $projectCode,
            'room' => $room,
            'expires_at' => gmdate('c', (int) $claims['exp']),
            'session' => [
                'token_id' => $claims['jti'],
                'user_id' => $claims['user_id'],
                'display_name' => $claims['display_name'],
                'capabilities' => $claims['capabilities'],
                'allowed_rooms' => $claims['allowed_rooms'],
                'allowed_room_prefixes' => $claims['allowed_room_prefixes'],
                'attachment_policy' => $claims['attachment_policy'],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    private function buildCallSessionAdmission(User $user, string $projectCode, CallSession $callSession, array $capabilities): array
    {
        $incident = $callSession->incident()->firstOrFail();
        $chatRoom = $this->incidentChatRoom($incident->id);
        $callRoom = $this->callSessionRoom($callSession->id);
        $config = $this->config();
        $builder = new \RealtimeTokenBuilder($config);
        $claims = $builder->forConferenceSession($this->baseContext(
            user: $user,
            projectCode: $projectCode,
            room: $chatRoom,
            capabilities: $capabilities,
            attachments: true,
            conference: true,
        ));

        $claims['allowed_rooms'] = [$chatRoom, $callRoom];
        $token = $builder->sign($claims);

        return [
            'token' => $token,
            'websocket_url' => $config->websocketUrl,
            'app_code' => $this->realtimeAppCode(),
            'project_code' => $projectCode,
            'room' => $chatRoom,
            'call_room' => $callRoom,
            'expires_at' => gmdate('c', (int) $claims['exp']),
            'session' => [
                'token_id' => $claims['jti'],
                'user_id' => $claims['user_id'],
                'display_name' => $claims['display_name'],
                'capabilities' => $claims['capabilities'],
                'allowed_rooms' => $claims['allowed_rooms'],
                'allowed_room_prefixes' => $claims['allowed_room_prefixes'],
                'attachment_policy' => $claims['attachment_policy'],
                'call_room' => $callRoom,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array<string, mixed>
     */
    private function baseContext(
        User $user,
        string $projectCode,
        string $room,
        array $capabilities,
        bool $presence = false,
        bool $attachments = false,
        bool $conference = false,
        array $allowedRoomPrefixes = [
            'chat.thread.',
            'call.session.',
            'presence.workspace.',
        ],
    ): array {
        return [
            'app_code' => $this->realtimeAppCode(),
            'project_code' => $projectCode,
            'user_id' => (string) $user->getKey(),
            'display_name' => $user->name,
            'email' => $user->email,
            'roles' => [$user->role->value],
            'room' => $room,
            'presence' => $presence,
            'attachments' => $attachments,
            'conference' => $conference,
            'capabilities' => $capabilities,
            'allowed_room_prefixes' => $allowedRoomPrefixes,
            'attachment_policy' => [
                'max_attachment_count' => 6,
                'max_attachment_bytes' => 2 * 1024 * 1024,
                'max_total_bytes_per_message' => 6 * 1024 * 1024,
                'chunk_events_per_minute' => 180,
                'chunk_bytes_per_minute' => 12 * 1024 * 1024,
            ],
        ];
    }

    private function callerIncident(User $user, int $incidentId): Incident
    {
        /** @var Incident|null $incident */
        $incident = Incident::query()
            ->whereKey($incidentId)
            ->where('citizen_id', $user->getKey())
            ->first();

        if (! $incident) {
            throw new AuthorizationException('Caller incident access denied.');
        }

        return $incident;
    }

    private function callerCallSession(User $user, int $callSessionId): CallSession
    {
        /** @var CallSession|null $callSession */
        $callSession = CallSession::query()
            ->with('incident')
            ->whereKey($callSessionId)
            ->where('citizen_id', $user->getKey())
            ->first();

        if (! $callSession) {
            throw new AuthorizationException('Caller call session access denied.');
        }

        return $callSession;
    }

    private function operatorIncident(User $user, int $incidentId): Incident
    {
        /** @var Incident|null $incident */
        $incident = Incident::query()
            ->whereKey($incidentId)
            ->where(function ($query) use ($user): void {
                $query->where('operator_id', $user->getKey())
                    ->orWhereExists(function ($subquery) use ($user): void {
                        $subquery->selectRaw('1')
                            ->from('incident_transfers')
                            ->whereColumn('incident_transfers.incident_id', 'incidents.id')
                            ->where(function ($transferQuery) use ($user): void {
                                $transferQuery->where('from_operator_id', $user->getKey())
                                    ->orWhere('to_operator_id', $user->getKey());
                            });
                    })
                    ->orWhereExists(function ($subquery) use ($user): void {
                        $subquery->selectRaw('1')
                            ->from('call_sessions')
                            ->join('call_participants', 'call_participants.call_session_id', '=', 'call_sessions.id')
                            ->whereColumn('call_sessions.incident_id', 'incidents.id')
                            ->where('call_participants.user_id', $user->getKey())
                            ->where('call_participants.participant_role', UserRole::Operator->value);
                    });
            })
            ->first();

        if (! $incident) {
            throw new AuthorizationException('Operator incident access denied.');
        }

        return $incident;
    }

    private function operatorCallSession(User $user, int $callSessionId): CallSession
    {
        /** @var CallSession|null $callSession */
        $callSession = CallSession::query()
            ->with('incident')
            ->whereKey($callSessionId)
            ->whereExists(function ($query) use ($user): void {
                $query->selectRaw('1')
                    ->from('call_participants')
                    ->whereColumn('call_participants.call_session_id', 'call_sessions.id')
                    ->where('call_participants.user_id', $user->getKey())
                    ->where('call_participants.participant_role', UserRole::Operator->value);
            })
            ->first();

        if (! $callSession) {
            throw new AuthorizationException('Operator call session access denied.');
        }

        return $callSession;
    }

    private function config(): \RealtimeConfig
    {
        $signingSecret = trim((string) $this->settings->get('realtime_token_signing_secret', ''));

        if ($signingSecret === '') {
            throw new RuntimeException('Realtime token signing secret is not configured.');
        }

        return new \RealtimeConfig([
            'issuer' => $this->realtimeIssuer(),
            'audience' => self::REALTIME_AUDIENCE,
            'signing_secret' => $signingSecret,
            'websocket_url' => $this->normalizeRealtimeWebsocketUrl(
                (string) $this->settings->get('realtime_url', 'https://realtime.pbb.ph')
            ),
            'token_ttl_seconds' => 1800,
        ]);
    }

    private function realtimeIssuer(): string
    {
        $configuredUrl = trim((string) config('app.url', ''));
        $configuredHost = is_string(parse_url($configuredUrl, PHP_URL_HOST))
            ? trim((string) parse_url($configuredUrl, PHP_URL_HOST))
            : '';

        if ($configuredHost !== '') {
            return $configuredHost;
        }

        $requestHost = request()?->getHost();
        if (is_string($requestHost) && trim($requestHost) !== '') {
            return trim($requestHost);
        }

        return 'pbb-hotline-backend';
    }

    private function incidentChatRoom(int $incidentId): string
    {
        return 'chat.thread.incident.'.$incidentId;
    }

    private function realtimeAppCode(): string
    {
        $value = trim((string) $this->settings->get('realtime_client_code'));

        return $value !== '' ? $value : 'clt_hotline';
    }

    private function realtimeProjectCode(string $scope, string $fallback): string
    {
        $value = trim((string) $this->settings->get('realtime_project_code_'.$scope));

        return $value !== '' ? $value : $fallback;
    }

    private function callSessionRoom(int $callSessionId): string
    {
        return 'call.session.'.$callSessionId;
    }

    private function normalizeRealtimeWebsocketUrl(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return 'wss://realtime.pbb.ph/realtime';
        }

        if (Str::startsWith($trimmed, ['ws://', 'wss://'])) {
            return preg_match('/\/realtime\/?$/', $trimmed) ? rtrim($trimmed, '/') : rtrim($trimmed, '/').'/realtime';
        }

        if (Str::startsWith($trimmed, 'https://')) {
            $trimmed = 'wss://'.ltrim(Str::after($trimmed, 'https://'), '/');
        } elseif (Str::startsWith($trimmed, 'http://')) {
            $trimmed = 'ws://'.ltrim(Str::after($trimmed, 'http://'), '/');
        } else {
            $trimmed = 'wss://'.ltrim($trimmed, '/');
        }

        return preg_match('/\/realtime\/?$/', $trimmed) ? rtrim($trimmed, '/') : rtrim($trimmed, '/').'/realtime';
    }
}
