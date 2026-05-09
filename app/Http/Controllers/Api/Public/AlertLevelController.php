<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Support\Settings\SettingsService;
use Illuminate\Http\JsonResponse;

class AlertLevelController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    public function show(): JsonResponse
    {
        $alertLevel = $this->settings->currentAlertLevel();

        return response()->json([
            'alert_level' => $alertLevel->value,
            'description' => $alertLevel->description(),
        ]);
    }
}
