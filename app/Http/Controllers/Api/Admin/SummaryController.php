<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Incidents\Models\IncidentType;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Sitreps\Models\SitrepRelayDelivery;
use App\Domain\Sitreps\Models\SitrepReport;
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
        $latestSitrepId = SitrepReport::query()
            ->latest('generated_at')
            ->latest('id')
            ->value('id');

        return response()->json([
            'alert_level' => $this->settings->currentAlertLevel()->value,
            'counts' => [
                'users' => User::query()->count(),
                'teams' => Team::query()->count(),
                'incident_types' => IncidentType::query()->count(),
                'resource_types' => ResourceType::query()->count(),
                'operators' => User::query()->where('role', UserRole::Operator)->count(),
                'sitrep_relay_pending' => SitrepRelayDelivery::query()
                    ->when($latestSitrepId, fn ($query) => $query->where('sitrep_report_id', $latestSitrepId))
                    ->whereIn('status', [SitrepRelayDelivery::STATUS_PENDING, SitrepRelayDelivery::STATUS_FAILED])
                    ->count(),
            ],
        ]);
    }
}
