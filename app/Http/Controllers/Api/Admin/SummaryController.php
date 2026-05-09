<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Incidents\Models\IncidentType;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Teams\Models\ResourceType;
use App\Domain\Teams\Models\Team;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Settings\SettingsService;
use Illuminate\Http\JsonResponse;

class SummaryController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'alert_level' => $this->settings->currentAlertLevel()->value,
            'counts' => [
                'users' => User::query()->count(),
                'teams' => Team::query()->count(),
                'incident_types' => IncidentType::query()->count(),
                'resource_types' => ResourceType::query()->count(),
                'operators' => User::query()->where('role', UserRole::Operator)->count(),
            ],
        ]);
    }
}
