<?php

namespace App\Http\Controllers\Api;

use App\Models\Hub;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class HubTokenController extends BaseApiController
{
    public function show(Hub $hub)
    {
        return $this->ok([
            'token' => $this->toTokenPayload($hub),
        ]);
    }

    public function store(Hub $hub)
    {
        $plainTextToken = Str::random(64);

        $token = $hub->token()->updateOrCreate(
            [],
            [
                'token_hash' => hash('sha256', $plainTextToken),
                'last_used_at' => null,
                'revoked_at' => $hub->status === 'active' ? null : now(),
            ]
        );

        $this->bumpHubsCacheVersion();

        return $this->ok([
            'token' => $this->toTokenPayload($hub->setRelation('token', $token)),
            'plain_text_token' => $plainTextToken,
        ], null, 201);
    }

    public function destroy(Hub $hub)
    {
        $hub->token()?->delete();
        $this->bumpHubsCacheVersion();

        return $this->ok();
    }

    private function toTokenPayload(Hub $hub): array
    {
        $token = $hub->token;

        return [
            'has_token' => (bool) $token,
            'is_active' => $hub->status === 'active' && $token && ! $token->revoked_at,
            'last_used_at' => $token?->last_used_at?->toIso8601String(),
            'revoked_at' => $token?->revoked_at?->toIso8601String(),
            'issued_at' => $token?->created_at?->toIso8601String(),
        ];
    }

    private function bumpHubsCacheVersion(): void
    {
        $store = Cache::store('file_data_api');
        $store->forever('hubs:version', ((int) $store->get('hubs:version', 1)) + 1);
    }
}
