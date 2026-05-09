<?php

namespace App\Http\Controllers\Api\Command;

use App\Domain\Shared\Enums\AlertLevel;
use App\Http\Controllers\Controller;
use App\Support\Realtime\RealtimeEventPublishService;
use App\Support\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AlertLevelController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly RealtimeEventPublishService $realtime,
    ) {
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'alert_level' => ['required', 'string', Rule::enum(AlertLevel::class)],
        ]);

        $previous = $this->settings->currentAlertLevel();
        $next = AlertLevel::from($validated['alert_level']);
        $realtime = null;

        if ($previous !== $next) {
            $this->settings->set('alert_level', $next->value);
            $realtime = $this->realtime->publishAlertLevelChanged($next->value);
        }

        return response()->json([
            'ok' => true,
            'changed' => $previous !== $next,
            'previous_alert_level' => $previous->value,
            'alert_level' => $next->value,
            'description' => $next->description(),
            'realtime' => $realtime,
        ]);
    }
}
