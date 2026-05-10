<?php

namespace App\Support\Compatibility;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LegacyCallerPayloadUsageLogger
{
    /**
     * @param array<int, string> $fields
     */
    public function log(Request $request, string $contract, array $fields): void
    {
        $fields = array_values(array_unique(array_filter($fields)));

        if ($fields === []) {
            return;
        }

        Log::info('Hotline legacy caller payload used.', [
            'contract' => $contract,
            'fields' => $fields,
            'method' => $request->method(),
            'path' => $request->path(),
            'route_name' => $request->route()?->getName(),
            'user_id' => $request->user()?->getKey(),
            'user_role' => $request->user()?->role?->value,
        ]);
    }
}
